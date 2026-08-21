<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\Dexter\Service\Indexer\AssetContainerType;
use BoldMinded\Dexter\Service\Indexer\CollectionType;
use BoldMinded\Dexter\Service\Indexer\ContentTypeFactory;
use BoldMinded\Dexter\Service\Indexer\TaxonomyType;
use BoldMinded\Dexter\Service\Indexer\UserType;
use Statamic\Facades\Site;

/**
 * Reads the Statamic-shaped config (indexes → searchables → fields) and
 * resolves it against real content.
 *
 * Statamic handles are user-renameable strings, so a configured searchable can
 * silently stop resolving. Everything here reports rather than throws, so the
 * CP can surface orphans instead of the site breaking on a rename.
 *
 * Anything that varies per source type -- whether a handle resolves, its
 * label, whether it carries a locale -- is delegated to the ContentType for
 * that type rather than decided here, so a new source type is a new class and
 * not an edit to three parallel match statements.
 */
class IndexConfig
{
    /**
     * Retained as the names the rest of the addon refers to these types by.
     * The values are the ContentType prefixes, so the two cannot drift.
     */
    public const TYPE_COLLECTION = CollectionType::PREFIX;
    public const TYPE_TAXONOMY = TaxonomyType::PREFIX;
    public const TYPE_ASSETS = AssetContainerType::PREFIX;
    public const TYPE_USERS = UserType::PREFIX;

    private ContentTypeFactory $types;

    /**
     * The factory is optional so this stays newable with a plain config array
     * -- tests construct it directly, and make() is called from places with no
     * container-resolved instance to hand. Supplied from the container when
     * one is available, so every caller shares the one registered instance.
     */
    public function __construct(private array $config, ?ContentTypeFactory $types = null)
    {
        $this->types = $types ?? self::resolveTypes();
    }

    public static function make(): self
    {
        return new self(config('dexter', []), self::resolveTypes());
    }

    /**
     * The registered ContentTypeFactory, falling back to a fresh one when the
     * container is unavailable -- during early boot, or in a unit test with no
     * application. The factory is stateless, so the fallback behaves
     * identically to the shared instance.
     */
    private static function resolveTypes(): ContentTypeFactory
    {
        return app()->bound(ContentTypeFactory::class)
            ? app(ContentTypeFactory::class)
            : new ContentTypeFactory();
    }

    /**
     * The ContentType handling a searchable key, or null when its prefix names
     * no known type.
     */
    public function contentType(string $key): ?Indexer\ContentType
    {
        return $this->types->make($key);
    }

    public function driverCredentials(string $driver): array
    {
        return $this->config['drivers'][$driver] ?? [];
    }

    public function searchLimit(): int
    {
        return (int) ($this->config['searchLimit'] ?? 100);
    }

    /**
     * @return string[]
     */
    public function indexNames(): array
    {
        return array_keys($this->config['indexes'] ?? []);
    }

    public function driverFor(string $index): ?string
    {
        return $this->config['indexes'][$index]['driver'] ?? null;
    }

    /**
     * The resolved index name: {env}_{index}_{locale}{suffix}.
     *
     * The locale segment appears only when the index declares `sites` and a
     * locale is supplied, so a single-site config resolves exactly as before.
     */
    public function resolveIndexName(string $index, ?string $locale = null): string
    {
        $env = $this->config['env'] ?? 'local';
        $suffix = $this->config['suffix'] ?? '';

        // The locale is passed rather than read from Site::current(): during a
        // CLI reindex the current site is the default one, not the site of the
        // item being indexed, so every localization would resolve to the same
        // name and land in the wrong index.
        if (is_callable($suffix)) {
            $suffix = (string) $suffix($index, $locale);
        }

        $localeSegment = ($locale !== null && $this->sitesFor($index) !== [])
            ? '_' . $locale
            : '';

        return $env . '_' . $index . $localeSegment . $suffix;
    }

    /**
     * Sites an index is split across, expanding 'all' to every configured site.
     *
     * Empty means the index is not localized: one index holds every locale,
     * distinguished by the `site` field on each document.
     *
     * @return string[]
     */
    public function sitesFor(string $index): array
    {
        $sites = $this->config['indexes'][$index]['sites'] ?? null;

        if ($sites === null) {
            return [];
        }

        if ($sites === 'all') {
            return Site::all()->map->handle()->values()->all();
        }

        return array_values(array_filter(array_map('strval', (array) $sites)));
    }

    /**
     * Every resolved name an index key maps to.
     *
     * A localized index answers to several names, so anything addressing an
     * index by its config key -- clearing, deleting, exporting -- has to work
     * through this rather than assuming one.
     *
     * @return string[]
     */
    public function resolveIndexNames(string $index): array
    {
        $sites = $this->sitesFor($index);

        if ($sites === []) {
            return [$this->resolveIndexName($index)];
        }

        return array_map(
            fn (string $site) => $this->resolveIndexName($index, $site),
            $sites
        );
    }

    /**
     * Every configured searchable, parsed and resolved.
     *
     * @return array<int, array{index: string, key: string, type: string, handle: string, fields: array, exists: bool, error: ?string}>
     */
    public function searchables(?string $onlyIndex = null): array
    {
        $rows = [];

        foreach ($this->config['indexes'] ?? [] as $index => $definition) {
            if ($onlyIndex !== null && $index !== $onlyIndex) {
                continue;
            }

            foreach ($definition['searchables'] ?? [] as $key => $settings) {
                $rows[] = $this->parseSearchable($index, $key, $settings);
            }
        }

        return $rows;
    }

    private function parseSearchable(string $index, string $key, array $settings): array
    {
        $type = $this->types->prefixOf($key);
        $handle = $this->types->handleOf($key);

        $row = [
            'index' => $index,
            'key' => $key,
            'type' => $type,
            'handle' => $handle,
            'fields' => $settings['fields'] ?? [],
            'exists' => false,
            'error' => null,
        ];

        $contentType = $this->types->make($key);

        if ($contentType === null) {
            $row['error'] = sprintf(
                'Unknown searchable type [%s]. Expected one of: %s.',
                $type,
                implode(', ', $this->types->prefixes())
            );

            return $row;
        }

        $row['exists'] = $contentType->exists($handle);
        $row['title'] = $contentType->getName($handle);

        if (! $row['exists']) {
            $row['error'] = sprintf(
                'No %s found for handle [%s]. It may have been renamed or deleted.',
                $type,
                $handle
            );
        }

        return $row;
    }

    /**
     * Resolvable sources grouped by rebuild target, for the dependent Content
     * dropdown.
     *
     * Keyed by the target's form value rather than the config name: a
     * localized index has one target per site and they do not share a source
     * list, since assets and users belong to none of them.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function rebuildMap(): array
    {
        $map = [];

        foreach ($this->rebuildTargets() as $target) {
            $map[$target['value']] = $target['sources'];
        }

        return $map;
    }

    /**
     * Whether a searchable's content carries a site, and so is split across
     * the localized indexes rather than living in the bare one.
     *
     * Entries and terms are per-site. Assets and users are not: they have no
     * locale, so CommandFactory resolves them with a null locale and they are
     * written to the unlocalized index name.
     *
     * Takes a type prefix rather than a full searchable key, which both are
     * valid input for -- a bare "users" is its own type, and a full key
     * resolves through the same prefix lookup.
     */
    public function isLocalizedType(string $type): bool
    {
        return $this->types->make($type)?->isLocalized() ?? false;
    }

    /**
     * Every rebuildable target: one per index key per site it writes to.
     *
     * A localized index yields one target per site, each covering only the
     * searchables that carry a site, plus one unlocalized target for the
     * assets and users that route to the bare index name. An index without
     * `sites` yields the single target it always had.
     *
     * Targets whose searchables all fail to resolve are omitted -- offering
     * them would be a dead end.
     *
     * @return array<int, array{value: string, index: string, site: ?string, resolved: string, sources: array<int, array{value: string, label: string}>}>
     */
    public function rebuildTargets(): array
    {
        $targets = [];

        foreach ($this->indexNames() as $index) {
            $sites = $this->sitesFor($index);
            $searchables = array_filter(
                $this->searchables($index),
                fn (array $s) => $s['error'] === null
            );

            if ($sites === []) {
                $targets[] = $this->target($index, null, $searchables);
                continue;
            }

            foreach ($sites as $site) {
                $targets[] = $this->target($index, $site, array_filter(
                    $searchables,
                    fn (array $s) => $this->isLocalizedType($s['type'])
                ));
            }

            // Assets and users still resolve to the bare name, so they need a
            // target of their own or nothing in the CP could rebuild them.
            $unlocalized = array_filter(
                $searchables,
                fn (array $s) => ! $this->isLocalizedType($s['type'])
            );

            if ($unlocalized !== []) {
                $targets[] = $this->target($index, null, $unlocalized);
            }
        }

        return array_values(array_filter(
            $targets,
            fn (array $t) => $t['sources'] !== []
        ));
    }

    /**
     * @param  array<int, array>  $searchables
     * @return array{value: string, index: string, site: ?string, resolved: string, sources: array<int, array{value: string, label: string}>}
     */
    private function target(string $index, ?string $site, array $searchables): array
    {
        return [
            // The resolved name doubles as the form value: it is unique per
            // target and readable in the markup, and rebuildTarget() maps it
            // back to the index/site pair.
            'value' => $this->resolveIndexName($index, $site),
            'index' => $index,
            'site' => $site,
            'resolved' => $this->resolveIndexName($index, $site),
            'sources' => array_values(array_map(
                fn (array $s) => ['value' => $s['key'], 'label' => $s['title']],
                $searchables
            )),
        ];
    }

    /**
     * One rebuild target by its form value, or null when it does not exist.
     *
     * The lookup is the allowlist for the rebuild action: a value absent here
     * names no real index/site pair and is refused.
     *
     * @return array{value: string, index: string, site: ?string, resolved: string, sources: array}|null
     */
    public function rebuildTarget(string $value): ?array
    {
        foreach ($this->rebuildTargets() as $target) {
            if ($target['value'] === $value) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The .json settings files directly inside a driver's config directory.
     *
     * Basenames only, sorted, and never recursive: this listing is what the
     * import action validates a chosen filename against, so anything it does
     * not contain -- a path in a parent directory, a symlinked file elsewhere,
     * a non-json file -- cannot be imported.
     *
     * @return string[]
     */
    public static function settingsFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || ! str_ends_with(strtolower($entry), '.json')) {
                continue;
            }

            if (is_file($directory . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Searchables whose configured handle no longer resolves.
     */
    public function orphans(): array
    {
        return array_values(array_filter(
            $this->searchables(),
            fn (array $row) => $row['error'] !== null
        ));
    }
}
