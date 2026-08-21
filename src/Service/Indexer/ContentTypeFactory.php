<?php

namespace BoldMinded\Dexter\Service\Indexer;

/**
 * Resolves a configured searchable key to the ContentType that handles it.
 *
 * The Statamic counterpart to ReIndexCommandsFactory in the EE and Craft
 * builds: both parse a prefixed source string and dispatch on the prefix. The
 * separator differs -- Statamic config uses "collection:pages" where EE uses
 * "channel_4" -- but the shape is the same.
 *
 * A key with no separator is the type on its own, which is how the single-
 * source `users` searchable is written.
 */
class ContentTypeFactory
{
    public const SEPARATOR = ':';

    /**
     * @var array<string, ContentType>
     */
    private array $types;

    public function __construct()
    {
        $this->types = [
            CollectionType::getPrefix() => new CollectionType(),
            TaxonomyType::getPrefix() => new TaxonomyType(),
            AssetContainerType::getPrefix() => new AssetContainerType(),
            UserType::getPrefix() => new UserType(),
        ];
    }

    /**
     * The ContentType for a searchable key, or null when its prefix names no
     * known type.
     *
     * Null rather than an exception: an unknown prefix is a config typo, and
     * IndexConfig reports it as an orphan alongside handles that no longer
     * resolve rather than breaking the Control Panel.
     */
    public function make(string $key): ?ContentType
    {
        return $this->types[$this->prefixOf($key)] ?? null;
    }

    /**
     * The type prefix of a searchable key: "collection" from
     * "collection:pages", or "users" from "users".
     */
    public function prefixOf(string $key): string
    {
        return str_contains($key, self::SEPARATOR)
            ? strstr($key, self::SEPARATOR, true)
            : $key;
    }

    /**
     * The handle of a searchable key: "pages" from "collection:pages". Empty
     * for a key that is a bare type, which is how `users` is written.
     */
    public function handleOf(string $key): string
    {
        return str_contains($key, self::SEPARATOR)
            ? substr(strstr($key, self::SEPARATOR), 1)
            : '';
    }

    /**
     * Every known type prefix, for reporting what a bad key should have been.
     *
     * @return string[]
     */
    public function prefixes(): array
    {
        return array_keys($this->types);
    }
}
