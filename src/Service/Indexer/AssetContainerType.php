<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * Assets in a container. The Statamic equivalent of EE's UploadLocation and
 * Craft's FileVolume.
 *
 * Assets carry no locale, so a site filter is deliberately ignored here rather
 * than narrowing the query to nothing.
 */
class AssetContainerType implements ContentType
{
    public const PREFIX = 'assets';

    public static function getPrefix(): string
    {
        return self::PREFIX;
    }

    public function exists(string $handle): bool
    {
        return AssetContainer::find($handle) !== null;
    }

    public function getName(string $handle): string
    {
        return AssetContainer::find($handle)?->title() ?? $handle;
    }

    public function query(string $handle, ?string $site = null): ?Builder
    {
        return Asset::query()->where('container', $handle);
    }

    public function isLocalized(): bool
    {
        return false;
    }
}
