<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\Indexer\ContentTypeFactory;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Query\Builder;
use Statamic\Contracts\Taxonomies\Term;

class IndexableFactory
{
    public function __construct(private ContentTypeFactory $types)
    {
    }

    /**
     * Wrap a Statamic content object in its Indexable adapter.
     */
    public function make(object $item): ?IndexableInterface
    {
        return match (true) {
            $item instanceof Entry => new IndexableEntry($item),
            $item instanceof Term => new IndexableTerm($item),
            $item instanceof Asset => new IndexableAsset($item),
            $item instanceof User => new IndexableUser($item),
            default => null,
        };
    }

    /**
     * Stream the content for a configured searchable.
     *
     * Uses lazy() so a full reindex holds one chunk in memory rather than the
     * whole collection.
     *
     * @return iterable<IndexableInterface>
     */
    public function stream(array $searchable, ?int $limit = null, ?string $site = null): iterable
    {
        $query = $this->query($searchable, $site);

        if ($query === null) {
            return;
        }

        if ($limit !== null) {
            foreach ($query->limit($limit)->get() as $item) {
                if ($indexable = $this->make($item)) {
                    yield $indexable;
                }
            }

            return;
        }

        foreach ($query->lazy(config('statamic.search.chunk_size', 100)) as $item) {
            if ($indexable = $this->make($item)) {
                yield $indexable;
            }
        }
    }

    /**
     * The query for a searchable's content, or null when its type is unknown.
     *
     * Each ContentType owns its own query, including whether a site filter
     * applies: entries and terms carry a locale and each localization routes
     * to its own index, while assets and users have none, so filtering those
     * by a site would return nothing rather than narrowing.
     *
     * @param  string|null  $site  restrict to one locale; ignored by types
     *                             that carry no site
     */
    private function query(array $searchable, ?string $site = null): ?Builder
    {
        return $this->types->make($searchable['type'])
            ?->query($searchable['handle'], $site);
    }
}
