<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Entries in a collection. The Statamic equivalent of EE's Channel and Craft's
 * Section.
 */
class CollectionType implements ContentType
{
    public const PREFIX = 'collection';

    public static function getPrefix(): string
    {
        return self::PREFIX;
    }

    public function exists(string $handle): bool
    {
        return Collection::handleExists($handle);
    }

    public function getName(string $handle): string
    {
        return Collection::findByHandle($handle)?->title() ?? $handle;
    }

    public function query(string $handle, ?string $site = null): ?Builder
    {
        $query = Entry::query()->where('collection', $handle);

        if ($site !== null) {
            $query->where('site', $site);
        }

        return $query;
    }

    public function isLocalized(): bool
    {
        return true;
    }
}
