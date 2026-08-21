<?php

namespace BoldMinded\Dexter\Http\Controllers;

use BoldMinded\DexterCore\Service\Indexer\Dummy;
use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Permissions;
use BoldMinded\Dexter\Service\IndexConfig;
use Statamic\Http\Controllers\CP\CpController;

class DexterController extends CpController
{
    public function index(IndexConfig $config, IndexerFactory $indexers)
    {
        $this->authorize(Permissions::VIEW);

        $indexes = [];

        foreach ($config->indexNames() as $name) {
            $provider = $indexers->make($name);
            // A localized index answers to one name per site, so its stats are
            // the sum across all of them; `resolved` keeps the first for the
            // places that can only show a single name.
            $resolvedNames = $config->resolveIndexNames($name);
            $stats = $this->stats($provider, $resolvedNames);

            $indexes[] = [
                'name' => $name,
                'driver' => $config->driverFor($name),
                'resolved' => $resolvedNames[0],
                'resolvedNames' => $resolvedNames,
                'localized' => $config->sitesFor($name) !== [],
                'configured' => ! $provider instanceof Dummy,
                'reachable' => $stats['reachable'],
                'error' => $stats['error'],
                'documents' => $stats['documents'],
                'searchables' => $config->searchables($name),
            ];
        }

        return view('dexter::index', [
            'indexes' => $indexes,
            'available' => $this->available($config, $indexers),
            // One entry per index per site it writes to. Already filtered to
            // targets with at least one resolvable source; offering the others
            // would be a dead end.
            'rebuildable' => $config->rebuildTargets(),
            'rebuildableMap' => $config->rebuildMap(),
            // Settings files already on disk, per driver, for the import
            // picker. Import reads from the export directory rather than
            // taking an upload, so the two halves stay symmetrical.
            'settingsFiles' => $this->settingsFiles($config),
            'settingsDirectories' => $this->settingsDirectories($config),
            // Target value => driver, so the import picker can narrow its file
            // list to the chosen index's provider.
            'targetDrivers' => collect($config->rebuildTargets())
                ->mapWithKeys(fn (array $t) => [$t['value'] => $config->driverFor($t['index'])])
                ->all(),
            'orphans' => $config->orphans(),
            'searchLimit' => $config->searchLimit(),
            'queueEnabled' => (bool) config('dexter.queue', false),
            'indexingEnabled' => (bool) config('dexter.enabled', true),
            'canManage' => (bool) \Statamic\Facades\User::current()?->can(Permissions::MANAGE),
        ]);
    }

    /**
     * The drivers in use, mapped to the directory their settings live in.
     *
     * Shown so an operator knows where to drop a file that was committed from
     * another environment.
     *
     * @return array<string, string>
     */
    private function settingsDirectories(IndexConfig $config): array
    {
        $directories = [];

        foreach ($config->indexNames() as $name) {
            $driver = $config->driverFor($name);

            if ($driver !== null) {
                $directories[$driver] = config_path($driver);
            }
        }

        return $directories;
    }

    /**
     * Exported settings files on disk, keyed by driver.
     *
     * Only basenames are returned, and only of .json files directly in the
     * driver's directory: the import action resolves a name back against this
     * same listing, so a path that is not here cannot be imported.
     *
     * @return array<string, string[]>
     */
    private function settingsFiles(IndexConfig $config): array
    {
        $files = [];

        foreach ($this->settingsDirectories($config) as $driver => $directory) {
            $files[$driver] = IndexConfig::settingsFilesIn($directory);
        }

        return $files;
    }

    /**
     * Every index the provider actually holds, with its stats.
     *
     * Deliberately not filtered to the configured map: an index left behind by
     * a renamed config entry, or built by another environment sharing the
     * provider, is exactly what an operator needs to see here. Rows carry a
     * `configured` flag so the view can mark the difference, and only
     * configured rows expose actions.
     *
     * @return array<int, array{name: string, documents: int, size: int, avg: int, configured: bool, key: ?string}>
     */
    private function available(IndexConfig $config, IndexerFactory $indexers): array
    {
        // Resolved index name => config key, to map provider rows back to
        // something the action routes can address.
        //
        // Built from resolveIndexNames() rather than the singular form: an
        // index declaring `sites` writes to one name per locale and never to
        // the bare name, so matching on the latter would mark every real
        // index unmanaged and hide its actions.
        $owned = [];

        foreach ($config->indexNames() as $name) {
            foreach ($config->resolveIndexNames($name) as $resolved) {
                $owned[$resolved] = $name;
            }

            // A localized index also owns its bare name whenever it holds a
            // searchable without a site. Assets and users have no locale, so
            // CommandFactory resolves them with a null one and writes them
            // there -- it is a live index, not a leftover from before `sites`.
            $unlocalized = array_filter(
                $config->searchables($name),
                fn (array $s) => ! $config->isLocalizedType($s['type'])
            );

            if ($config->sitesFor($name) !== [] && $unlocalized !== []) {
                $owned[$config->resolveIndexName($name)] = $name;
            }
        }

        $seen = [];
        $rows = [];

        foreach ($config->indexNames() as $name) {
            $provider = $indexers->make($name);

            if ($provider instanceof Dummy) {
                continue;
            }

            try {
                $stats = $provider->list();
            } catch (\Throwable) {
                continue;
            }

            foreach ($stats as $stat) {
                $indexName = $stat['indexName'] ?? null;

                if ($indexName === null || isset($seen[$indexName])) {
                    continue;
                }

                $seen[$indexName] = true;

                $rows[] = [
                    'name' => $indexName,
                    'documents' => (int) ($stat['documentCount'] ?? 0),
                    'size' => (int) ($stat['totalSize'] ?? 0),
                    'avg' => (int) ($stat['avgSize'] ?? 0),
                    'configured' => isset($owned[$indexName]),
                    'key' => $owned[$indexName] ?? null,
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Live document count for one config key, summed across its locales.
     *
     * A localized index is several provider-side indexes, so a single count
     * would report one locale and read as the whole thing. Locales that do not
     * exist yet contribute nothing rather than counting as zero, so a key is
     * only reported as uncreated when none of its names are present.
     *
     * A provider that cannot be reached is reported as such rather than
     * throwing: the status screen is where an operator looks to find out the
     * provider is down, so it has to render in exactly that situation.
     *
     * @param  string[]  $resolvedNames
     * @return array{reachable: bool, documents: ?int, error: ?string}
     */
    private function stats($provider, array $resolvedNames): array
    {
        if ($provider instanceof Dummy) {
            return ['reachable' => false, 'documents' => null, 'error' => null];
        }

        try {
            $documents = null;

            foreach ($provider->list() as $stat) {
                if (in_array($stat['indexName'] ?? null, $resolvedNames, true)) {
                    $documents = (int) ($documents ?? 0) + (int) ($stat['documentCount'] ?? 0);
                }
            }

            // documents stays null when nothing matched: reachable, but this
            // index has not been created yet -- normal before a first reindex.
            return ['reachable' => true, 'documents' => $documents, 'error' => null];
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'documents' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
