<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableFileInterface;
use BoldMinded\DexterCore\Contracts\LoggerInterface;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Term;

/**
 * Writes AI-generated values back onto the asset itself.
 *
 * Without this the enrichment would live only inside the search index, so it
 * would be invisible in the Control Panel, absent from ordinary content
 * queries, and lost the moment the index was rebuilt from source. Persisting
 * it makes the asset the record and the index a projection of it.
 */
class FileUpdater
{
    private array $options;

    public function __construct(
        private IndexableFileInterface $file,
        private ConfigInterface $config,
        private LoggerInterface $logger,
    ) {
        $this->options = $this->config->get(
            sprintf('parse%sContents', $this->file->isImage() ? 'Image' : 'Document')
        ) ?: [];
    }

    /**
     * @param  array{altText?: string, description?: string, tags?: array}  $values
     */
    public function update(array $values): bool
    {
        $asset = $this->asset();

        if ($asset === null) {
            return false;
        }

        $dirty = false;

        $dirty = $this->applyText($asset, 'altTextFieldHandle', 'AltText', $values['altText'] ?? null) || $dirty;
        $dirty = $this->applyText($asset, 'descriptionFieldHandle', 'Description', $values['description'] ?? null) || $dirty;
        $dirty = $this->applyTerms($asset, $values['tags'] ?? []) || $dirty;

        if (! $dirty) {
            return false;
        }

        try {
            // Quietly: saving the asset re-fires AssetSaved, and re-entering
            // the indexer from inside the indexing pipeline would recurse.
            $asset->saveQuietly();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not persist AI values on asset [%s]: %s',
                $asset->id(),
                $e->getMessage()
            ));

            return false;
        }

        return true;
    }

    /**
     * Write one generated string to its configured blueprint field.
     *
     * The create/replace distinction matters: create fills an empty field,
     * replace overwrites whatever is there. An author's own wording should
     * survive a re-index unless the site explicitly asked otherwise.
     */
    private function applyText(Asset $asset, string $handleKey, string $flag, ?string $value): bool
    {
        $handle = $this->options[$handleKey] ?? '';

        if ($handle === '' || $value === null || trim($value) === '') {
            return false;
        }

        $existing = $this->read($asset, $handle);
        $isEmpty = $existing === null || trim((string) $existing) === '';

        $create = ($this->options['create' . $flag] ?? false) === true;
        $replace = ($this->options['replace' . $flag] ?? false) === true;

        if (! ($replace || ($create && $isEmpty))) {
            return false;
        }

        if ((string) $existing === $value) {
            return false;
        }

        $this->write($asset, $handle, $value);

        return true;
    }

    /**
     * Turn generated keywords into taxonomy terms and relate them.
     *
     * Terms are created when missing, since a keyword the AI produced will
     * rarely exist already. Without a configured taxonomy the keywords stay
     * on the indexed document only.
     */
    private function applyTerms(Asset $asset, array $tags): bool
    {
        $handle = $this->options['categoriesFieldHandle'] ?? '';
        $taxonomy = $this->options['taxonomyHandle'] ?? '';

        if ($handle === '' || $taxonomy === '' || $tags === []) {
            return false;
        }

        $create = ($this->options['createCategories'] ?? false) === true;
        $replace = ($this->options['replaceCategories'] ?? false) === true;

        if (! $create && ! $replace) {
            return false;
        }

        $existing = $replace ? [] : (array) ($this->read($asset, $handle) ?? []);
        $slugs = $existing;

        foreach ($tags as $tag) {
            $slug = $this->ensureTerm($taxonomy, (string) $tag);

            if ($slug !== null && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        if ($slugs === $existing) {
            return false;
        }

        $this->write($asset, $handle, array_values($slugs));

        return true;
    }

    private function ensureTerm(string $taxonomy, string $title): ?string
    {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        $slug = str($title)->slug()->value();

        try {
            $existing = Term::query()
                ->where('taxonomy', $taxonomy)
                ->where('slug', $slug)
                ->first();

            if ($existing) {
                return $slug;
            }

            Term::make()
                ->taxonomy($taxonomy)
                ->slug($slug)
                ->data(['title' => $title])
                ->save();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not create term [%s] in taxonomy [%s]: %s',
                $title,
                $taxonomy,
                $e->getMessage()
            ));

            return null;
        }

        return $slug;
    }

    /**
     * Read a value by handle, resolving dot notation into nested data.
     *
     * Statamic's own get() treats a dotted string as a literal key, so
     * "seo.description" would look for a field named exactly that rather than
     * descending into a Group or Grid. Sites configure nested handles, so the
     * descent is done here.
     */
    private function read(Asset $asset, string $handle): mixed
    {
        if (! str_contains($handle, '.')) {
            return $asset->get($handle);
        }

        $segments = explode('.', $handle);
        $cursor = $asset->get(array_shift($segments));

        foreach ($segments as $segment) {
            if (is_array($cursor)) {
                $cursor = $cursor[$segment] ?? null;

                continue;
            }

            // A Value object or anything else non-traversable ends the walk:
            // there is no further nesting to descend into.
            return null;
        }

        return $cursor;
    }

    /**
     * Write a value by handle, building intermediate arrays for a dot path.
     *
     * set() would otherwise create a literal key containing dots, which
     * Statamic never reads back -- the data would be silently orphaned in the
     * asset's meta file.
     */
    private function write(Asset $asset, string $handle, mixed $value): void
    {
        if (! str_contains($handle, '.')) {
            $asset->set($handle, $value);

            return;
        }

        $segments = explode('.', $handle);
        $root = array_shift($segments);

        $existing = $asset->get($root);
        $data = is_array($existing) ? $existing : [];

        $cursor = &$data;

        foreach ($segments as $segment) {
            // Anything already there that is not an array cannot be descended
            // into, so it is replaced rather than silently dropping the write.
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor = $value;
        unset($cursor);

        $asset->set($root, $data);
    }

    private function asset(): ?Asset
    {
        $entity = $this->file->getEntity();

        return $entity instanceof Asset ? $entity : null;
    }
}
