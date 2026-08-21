<?php

namespace BoldMinded\Dexter\Http\Controllers;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Service\Indexer\Reindexer;
use BoldMinded\Dexter\Permissions;
use BoldMinded\Dexter\Service\IndexConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Mutating index operations.
 *
 * Every action authorizes against the manage permission and refuses any index
 * absent from the configured map, matching the allowlist rule the rest of the
 * addon follows -- the CP is not a way around it.
 */
class IndexActionController extends CpController
{
    /**
     * Rebuild an index, optionally narrowed to one content source.
     *
     * The Content dropdown is populated from the Index choice in the browser,
     * so the pair is re-validated here: the two fields arrive independently
     * and nothing stops a request pairing an index with a source belonging to
     * a different one.
     */
    public function reindex(Request $request)
    {
        $this->authorizeManage();

        $value = (string) $request->input('index');

        if ($value === '') {
            return $this->flashFailure('Choose an index to rebuild.');
        }

        // The posted value names one index/site pair. Resolving it through the
        // target list is the allowlist: anything not built from the config
        // resolves to null and is refused, so assertConfigured's guarantee
        // still holds for a value that is no longer a bare config key.
        $target = IndexConfig::make()->rebuildTarget($value);

        if ($target === null) {
            abort(404, __('dexter::messages.unknown_index', ['index' => $value]));
        }

        // Empty means every source in this target.
        $searchable = $request->input('searchable') ?: null;

        if ($searchable !== null) {
            $known = array_column($target['sources'], 'value');

            if (! in_array($searchable, $known, true)) {
                return $this->flashFailure(sprintf(
                    'Unknown content source [%s] in index [%s].',
                    $searchable,
                    $target['resolved']
                ));
            }
        }

        try {
            $result = app(Reindexer::class)->run(
                $target['index'],
                $searchable,
                null,
                $target['site']
            );
        } catch (\Throwable $e) {
            return $this->flashFailure($e->getMessage());
        }

        if ($result['queued'] > 0) {
            return $this->flashSuccess(__('dexter::messages.reindex_queued', [
                'index' => $target['resolved'],
            ]));
        }

        return $this->flashSuccess(__('dexter::messages.reindex_started', [
            'index' => $target['resolved'],
            'count' => $result['indexed'],
        ]));
    }

    /**
     * Empty the index but keep it, so searches return nothing rather than
     * erroring while a reindex runs.
     */
    public function clear(string $index)
    {
        $this->authorizeManage();
        $this->assertConfigured($index);

        return $this->acrossSites(
            $index,
            fn (string $resolved) => $this->provider($index)->clear($resolved),
            'dexter::messages.index_cleared'
        );
    }

    /**
     * Remove the index entirely. Distinct from clear: settings and any
     * provider-side configuration go with it.
     */
    public function destroy(string $index)
    {
        $this->authorizeManage();
        $this->assertConfigured($index);

        return $this->acrossSites(
            $index,
            fn (string $resolved) => $this->provider($index)->deleteIndex($resolved),
            'dexter::messages.index_deleted'
        );
    }

    /**
     * Write the provider's settings to config/{driver}/{index}[_{site}].json.
     *
     * Named for the config key rather than the resolved name: the resolved
     * name carries the environment prefix, and these files are meant to be
     * committed and moved between environments, not duplicated per env. The
     * site travels with the filename because settings genuinely differ per
     * locale -- stop words and synonyms are language-specific -- so a single
     * file per key would let one locale's tuning overwrite another's.
     */
    public function export(Request $request)
    {
        $this->authorizeManage();

        $target = $this->target($request);

        if ($target === null) {
            abort(404, __('dexter::messages.unknown_index', [
                'index' => (string) $request->input('index'),
            ]));
        }

        $index = $target['index'];
        $site = $target['site'];

        $driver = IndexConfig::make()->driverFor($index);

        if ($driver === null) {
            return $this->flashFailure(sprintf('No driver configured for index [%s].', $index));
        }

        $resolved = $target['resolved'];
        $file = $index . ($site !== null ? '_' . $site : '') . '.json';
        $directory = config_path($driver);

        // The provider call is inside the try as well: an unreachable
        // Meilisearch or Algolia is the common failure here, and it should
        // flash like every other action rather than 500 the CP.
        try {
            $settings = $this->provider($index)->export($resolved);

            File::ensureDirectoryExists($directory);
            File::put($directory . '/' . $file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } catch (\Throwable $e) {
            return $this->flashFailure($e->getMessage());
        }

        return $this->flashSuccess(__('dexter::messages.settings_exported', [
            'index' => $resolved,
            'path' => $driver . '/' . $file,
        ]));
    }

    /**
     * Apply a settings file from the driver's config directory to an index.
     *
     * The file is chosen from what has already been exported rather than
     * uploaded: these files are committed and moved between environments, so
     * the ones worth importing are the ones on disk. It also means the browser
     * never supplies file contents -- only a name, which is checked against
     * the directory listing before anything is read.
     */
    public function import(Request $request)
    {
        $this->authorizeManage();

        $target = $this->target($request);

        if ($target === null) {
            abort(404, __('dexter::messages.unknown_index', [
                'index' => (string) $request->input('index'),
            ]));
        }

        $index = $target['index'];

        $config = IndexConfig::make();
        $driver = $config->driverFor($index);

        if ($driver === null) {
            return $this->flashFailure(sprintf('No driver configured for index [%s].', $index));
        }

        $file = (string) $request->input('settings');

        if ($file === '') {
            return $this->flashFailure('Choose a settings file to import.');
        }

        $directory = config_path($driver);

        // The listing is the allowlist. A basename comparison against it means
        // traversal ("../../.env"), an absolute path, or any file outside this
        // directory simply is not found, without needing to reason about how
        // the string would resolve.
        if (! in_array($file, IndexConfig::settingsFilesIn($directory), true)) {
            return $this->flashFailure(sprintf('Unknown settings file [%s].', $file));
        }

        $decoded = json_decode((string) File::get($directory . '/' . $file), true);

        if (! is_array($decoded)) {
            return $this->flashFailure(sprintf('[%s] is not valid JSON.', $file));
        }

        $resolved = $target['resolved'];
        $imported = $this->provider($index)->import($resolved, $decoded);

        return $imported
            ? $this->flashSuccess(__('dexter::messages.settings_imported', [
                'index' => $resolved,
                'file' => $file,
            ]))
            : $this->flashFailure('The provider rejected those settings.');
    }

    /**
     * The index/site pair an export or import targets.
     *
     * Resolved through the same target list the Rebuild dropdown is built
     * from, which doubles as the allowlist: a value naming no real
     * provider-side index comes back null and the caller refuses it.
     *
     * @return array{value: string, index: string, site: ?string, resolved: string, sources: array}|null
     */
    private function target(Request $request): ?array
    {
        $value = (string) $request->input('index');

        return $value === '' ? null : IndexConfig::make()->rebuildTarget($value);
    }

    /**
     * Empty an index the provider holds but this site does not configure.
     */
    public function clearUnmanaged(Request $request)
    {
        return $this->actOnUnmanaged(
            $request,
            fn ($provider, string $name) => $provider->clear($name),
            'dexter::messages.index_cleared'
        );
    }

    /**
     * Remove an index the provider holds but this site does not configure.
     *
     * The usual reason one exists is a config key that used to resolve to it:
     * enabling `sites` on an index leaves the old unlocalized name behind,
     * populated and drifting, with nothing writing to it. Without this there
     * is no way to remove it through Dexter at all.
     */
    public function destroyUnmanaged(Request $request)
    {
        return $this->actOnUnmanaged(
            $request,
            fn ($provider, string $name) => $provider->deleteIndex($name),
            'dexter::messages.index_deleted'
        );
    }

    /**
     * Act on a resolved index name that no config key maps to.
     *
     * Deliberately narrow. The name is checked against what the provider
     * actually holds and against every configured name, so this cannot be
     * used to reach a managed index -- those keep going through the
     * key-based actions and their allowlist. Both operations only ever
     * remove data, never write.
     */
    private function actOnUnmanaged(Request $request, callable $action, string $successKey)
    {
        $this->authorizeManage();

        $name = (string) $request->input('name');

        if ($name === '') {
            return $this->flashFailure('No index named.');
        }

        $config = IndexConfig::make();

        // A configured name belongs to the key-based actions, which apply the
        // allowlist and act across every locale.
        foreach ($config->indexNames() as $key) {
            if (in_array($name, $config->resolveIndexNames($key), true)) {
                return $this->flashFailure(sprintf(
                    'Index [%s] is managed by Dexter; use its own actions instead.',
                    $name
                ));
            }
        }

        // Any configured index will do: the provider is per-driver, not per
        // index, and an unmanaged index lives on the same one.
        $first = $config->indexNames()[0] ?? null;

        if ($first === null) {
            return $this->flashFailure('No provider is configured.');
        }

        $provider = app(IndexerFactory::class)->make($first);

        // Only names the provider actually reports, so a crafted request
        // cannot make Dexter issue deletes for arbitrary strings.
        try {
            $known = array_column($provider->list(), 'indexName');
        } catch (\Throwable $e) {
            return $this->flashFailure($e->getMessage());
        }

        if (! in_array($name, $known, true)) {
            return $this->flashFailure(sprintf('Unknown index [%s].', $name));
        }

        $response = $action($provider, $name);

        return $response->isSuccess()
            ? $this->flashSuccess(__($successKey, ['index' => $name]))
            : $this->flashFailure(implode('; ', $response->getErrors()));
    }

    private function authorizeManage(): void
    {
        $this->authorize(Permissions::MANAGE);
    }

    /**
     * The configured map is the allowlist. An index absent from it is not
     * addressable, however the request was constructed.
     */
    private function assertConfigured(string $index): void
    {
        if (! in_array($index, IndexConfig::make()->indexNames(), true)) {
            abort(404, __('dexter::messages.unknown_index', ['index' => $index]));
        }
    }

    private function provider(string $index)
    {
        return app(IndexerFactory::class)->make($index);
    }

    /**
     * Run a destructive action against every index a config key maps to.
     *
     * A localized index answers to one name per site, so acting on a single
     * resolved name would empty one locale and quietly leave the rest --
     * which reads as success. Any failure is reported and the remaining sites
     * are still attempted, so one unreachable locale cannot strand the others.
     */
    private function acrossSites(string $index, callable $action, string $successKey)
    {
        $names = IndexConfig::make()->resolveIndexNames($index);
        $errors = [];
        $done = [];

        foreach ($names as $resolved) {
            $response = $action($resolved);

            if ($response->isSuccess()) {
                $done[] = $resolved;
            } else {
                $errors[] = $resolved . ': ' . implode('; ', $response->getErrors());
            }
        }

        if ($errors !== []) {
            return $this->flashFailure(implode(' | ', $errors));
        }

        return $this->flashSuccess(__($successKey, ['index' => implode(', ', $done)]));
    }

    private function flashSuccess(string $message)
    {
        session()->flash('success', $message);

        return redirect()->route('statamic.cp.dexter.index');
    }

    private function flashFailure(string $error)
    {
        session()->flash('error', __('dexter::messages.action_failed', ['error' => $error]));

        return redirect()->route('statamic.cp.dexter.index');
    }
}
