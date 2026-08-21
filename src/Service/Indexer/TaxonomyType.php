<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Terms in a taxonomy. The Statamic equivalent of the CategoryGroup type in
 * the EE and Craft builds.
 */
class TaxonomyType implements ContentType
{
    public const PREFIX = 'taxonomy';

    public static function getPrefix(): string
    {
        return self::PREFIX;
    }

    public function exists(string $handle): bool
    {
        return Taxonomy::handleExists($handle);
    }

    public function getName(string $handle): string
    {
        return Taxonomy::findByHandle($handle)?->title() ?? $handle;
    }

    public function query(string $handle, ?string $site = null): ?Builder
    {
        $query = Term::query()->where('taxonomy', $handle);

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
