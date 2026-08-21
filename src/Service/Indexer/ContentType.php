<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Statamic\Contracts\Query\Builder;

/**
 * One indexable content source: collections, taxonomies, asset containers,
 * users.
 *
 * The Statamic counterpart to the EE build's Service/Indexer/ContentType and
 * the Craft build's services/indexer/ContentType. Those identify a source by
 * a prefixed string ("channel_4", "entries.article"); Statamic config uses the
 * same shape -- "collection:pages" -- with the prefix naming the type and the
 * remainder naming the handle.
 *
 * Everything that varies per source type lives behind this interface, so
 * adding one means adding a class rather than editing the parallel match
 * statements that used to sit in IndexConfig and IndexableFactory.
 */
interface ContentType
{
    /**
     * The config prefix identifying this type, without its separator --
     * "collection", "taxonomy", "assets", "users".
     */
    public static function getPrefix(): string;

    /**
     * Whether the configured handle still resolves to real content.
     *
     * Statamic handles are user-renameable, so a configured source can stop
     * existing without warning. Reported rather than thrown so the Control
     * Panel can surface an orphan instead of the site breaking on a rename.
     */
    public function exists(string $handle): bool;

    /**
     * The label an editor would recognise -- a Collection's title rather than
     * its handle -- falling back to the handle when it no longer resolves.
     */
    public function getName(string $handle): string;

    /**
     * A query for every item this source holds, or null when the handle does
     * not resolve.
     *
     * @param  string|null  $site  restrict to one locale; ignored by types
     *                             that carry no site
     */
    public function query(string $handle, ?string $site = null): ?Builder;

    /**
     * Whether this type's content carries a site, and so is split across the
     * localized indexes rather than living in the bare one.
     *
     * Entries and terms are per-site. Assets and users are not: they have no
     * locale, so they resolve with a null locale and are written to the
     * unlocalized index name.
     */
    public function isLocalized(): bool;
}
