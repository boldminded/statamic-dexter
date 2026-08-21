<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\DexterCore\Service\AssetUrl;
use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;

/**
 * Resolve an assets field to URLs.
 *
 * Statamic stores only the path ("bg-1.jpg"), so the asset must be looked up
 * against the container named in the field's own config -- a bare path is not
 * resolvable on its own, and Asset::find() returns null for one.
 *
 * Unresolvable references fall back to the stored path rather than being
 * dropped, so a missing or moved file still leaves something searchable in the
 * document.
 */
class Assets implements FieldTypeInterface
{
    public function getTypes(): array
    {
        return ['assets'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        return [$field->handle() => $this->urls($field, $value)];
    }

    /**
     * @return string|array<int, string>
     */
    private function urls(Field $field, mixed $value): string|array
    {
        $paths = FieldValue::scalarList($value);
        $format = $this->assetUrlFormat();

        if ($format === AssetUrl::PATH) {
            return $this->collapseSingle($field, $paths);
        }

        $container = $field->config()['container'] ?? null;
        $out = [];

        foreach ($paths as $path) {
            $out[] = $this->assetUrl((string) $path, $container, $format);
        }

        return $this->collapseSingle($field, $out);
    }

    private function assetUrl(string $path, ?string $container, string $format): string
    {
        $asset = null;

        // An already-qualified reference ("assets::bg-1.jpg") resolves
        // directly; a bare path needs its container.
        if (str_contains($path, '::')) {
            $asset = Asset::find($path);
        } elseif ($container !== null) {
            $asset = AssetContainer::find($container)?->asset($path);
        }

        if ($asset === null) {
            return $path;
        }

        return AssetUrl::pick(
            $format,
            $path,
            $asset->url(),
            $asset->absoluteUrl(),
        );
    }

    /**
     * Reads the same key the EE and Craft builds use, via core's shared
     * vocabulary, so an unrecognised value falls back consistently.
     */
    private function assetUrlFormat(): string
    {
        $configured = config('dexter.assetUrls');

        return AssetUrl::isValid($configured) ? $configured : AssetUrl::DEFAULT;
    }

    /**
     * Flatten a single-value list when the field cannot hold more than one.
     *
     * `max_files: 1` is the assets field's own declaration that its value is
     * singular, so the indexed document follows suit. An unset or larger limit
     * keeps the array, even when only one file happens to be selected -- the
     * shape follows the field definition, not the current contents, so it stays
     * stable as content changes.
     *
     * @param  array<int, string>  $values
     * @return string|array<int, string>
     */
    private function collapseSingle(Field $field, array $values): string|array
    {
        $max = $field->config()['max_files'] ?? null;

        if ((int) $max !== 1) {
            return $values;
        }

        return $values === [] ? '' : (string) reset($values);
    }
}
