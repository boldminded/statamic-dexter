<?php

namespace BoldMinded\Dexter\Service\Indexer;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\DexterCore\Service\Indexer\IndexCategoryCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexEntryCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexFileCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexUserCommand;
use BoldMinded\Dexter\Service\IndexConfig;
use BoldMinded\Dexter\Service\Pipeline\ScopePipelines;

/**
 * Builds dexter-core index commands for Statamic content.
 *
 * Core picks the command class by scope; each runs the configured pipeline
 * chain over the indexable's values and returns the document to be sent to
 * the search provider.
 */
class CommandFactory
{
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * @return IndexCommand|null null when the indexable's source is not
     *                           configured for any index
     */
    public function make(IndexableInterface $indexable, string $queueJobName = ''): ?IndexCommand
    {
        $indexName = $this->indexNameFor($indexable);

        if ($indexName === null) {
            return null;
        }

        $arguments = [
            'indexName' => $indexName,
            'indexable' => $indexable,
            'config' => $this->config,
            'pipelines' => ScopePipelines::build(
                $indexable->getScope(),
                config('dexter', [])
            ),
            'queueJobName' => $queueJobName,
        ];

        return match ($indexable->getScope()) {
            'entries' => new IndexEntryCommand(...$arguments),
            'categories' => new IndexCategoryCommand(...$arguments),
            'files' => new IndexFileCommand(...$arguments),
            'users' => new IndexUserCommand(...$arguments),
            default => null,
        };
    }

    /**
     * The resolved index name to write to, carrying the environment prefix,
     * the item's locale when the index is localized, and any suffix --
     * `local_content` or `local_content_fr`.
     *
     * This is NOT the config key. Use indexKeyFor() to look anything up in
     * `dexter.indexes`, which is keyed by the bare name.
     */
    public function indexNameFor(IndexableInterface $indexable): ?string
    {
        $key = $this->indexKeyFor($indexable);

        if ($key === null) {
            return null;
        }

        // Resolved through IndexConfig rather than core's Config so the
        // locale can take part. Core applies the environment prefix when
        // reading `indices.`, which leaves no room to insert a locale
        // segment.
        return $this->indexConfig()->resolveIndexName($key, $this->localeFor($indexable));
    }

    /**
     * The site an item belongs to, or null when it has none.
     *
     * Read from the already-indexed `site` value rather than the entity:
     * core's IndexableInterface has no locale method and cannot gain one
     * without changing the EE and Craft builds. It is present for entries and
     * terms and absent for assets and users -- exactly where a locale exists.
     */
    private function localeFor(IndexableInterface $indexable): ?string
    {
        $site = $indexable->get('site');

        return is_string($site) && $site !== '' ? $site : null;
    }

    private function indexConfig(): IndexConfig
    {
        return app(IndexConfig::class);
    }

    /**
     * The bare config key for this indexable's index -- for example
     * `content`. Reads the untranslated config, because core's Config::get()
     * applies the environment prefix to anything under `indices.`.
     */
    public function indexKeyFor(IndexableInterface $indexable): ?string
    {
        $indices = $this->config->getAll()['indices'][$indexable->getScope()] ?? [];

        foreach ($indexable->getTypes() as $type) {
            if (isset($indices[$type])) {
                return $indices[$type];
            }
        }

        return null;
    }
}
