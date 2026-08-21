<?php

namespace BoldMinded\Dexter\Service\Indexer;

use BoldMinded\Dexter\Service\IndexableFactory;
use BoldMinded\Dexter\Jobs\IndexAssetJob;
use BoldMinded\Dexter\Jobs\IndexEntryJob;
use BoldMinded\Dexter\Jobs\IndexTermJob;
use BoldMinded\Dexter\Jobs\IndexUserJob;
use BoldMinded\Dexter\Service\IndexConfig;
use Closure;
use InvalidArgumentException;

/**
 * Bulk reindexing, shared by the Control Panel and the console command so both
 * behave identically.
 *
 * Content is streamed rather than collected: IndexableFactory::stream() uses
 * lazy(), so a reindex holds one chunk in memory regardless of collection size.
 */
class Reindexer
{
    public function __construct(
        private IndexConfig $config,
        private IndexableFactory $factory,
        private Indexer $indexer,
    ) {
    }

    /**
     * @param  string|null  $only  restrict to one searchable key, e.g. "collection:pages"
     * @param  Closure|null  $progress  called with each item's label
     * @param  string|null  $site  restrict to one locale. Narrows which content is
     *                             streamed, not where it lands: each document routes
     *                             to its own index from its own `site` value, so a
     *                             locale can be selected but never overridden.
     * @return array{indexed: int, failed: int, queued: int, skipped: string[]}
     */
    public function run(string $index, ?string $only = null, ?Closure $progress = null, ?string $site = null): array
    {
        if (! in_array($index, $this->config->indexNames(), true)) {
            throw new InvalidArgumentException(
                __('dexter::messages.unknown_index', ['index' => $index])
            );
        }

        if ($site !== null && ! in_array($site, $this->config->sitesFor($index), true)) {
            throw new InvalidArgumentException(
                sprintf('Index [%s] is not built for site [%s].', $index, $site)
            );
        }

        $searchables = $this->config->searchables($index);

        // A locale rebuild covers only the content that has one. Assets and
        // users resolve to the bare index name regardless of the site being
        // rebuilt, so including them here would reindex them once per locale
        // into an index this run is not targeting.
        if ($site !== null) {
            $searchables = array_values(array_filter(
                $searchables,
                fn (array $s) => $this->config->isLocalizedType($s['type'])
            ));
        }

        if ($only !== null) {
            $searchables = array_values(array_filter(
                $searchables,
                fn (array $s) => $s['key'] === $only
            ));

            if ($searchables === []) {
                throw new InvalidArgumentException(
                    sprintf('Unknown searchable [%s] in index [%s].', $only, $index)
                );
            }
        }

        $indexed = 0;
        $failed = 0;
        $queued = 0;
        $skipped = [];

        foreach ($searchables as $searchable) {
            // An orphaned handle is reported rather than skipped silently, so
            // a renamed collection cannot quietly stop being indexed.
            if ($searchable['error'] !== null) {
                $skipped[] = $searchable['key'] . ' — ' . $searchable['error'];
                continue;
            }

            foreach ($this->factory->stream($searchable, null, $site) as $indexable) {
                // Invoked, not ->call()'d: rebinding would detach the caller's $this.
                $progress?->__invoke($searchable['key'], $indexable->getUniqueId());

                if ($this->shouldQueue()) {
                    // The indexable's own site, not the run's: a null $site
                    // streams every locale, and each job has to re-resolve the
                    // one it was created from.
                    $this->dispatch(
                        $indexable->getScope(),
                        $indexable->getId(),
                        is_string($indexable->get('site')) ? $indexable->get('site') : null
                    );
                    $queued++;
                    continue;
                }

                $response = $this->indexer->index($indexable, false);

                if ($response !== null && $response->isSuccess()) {
                    $indexed++;
                } else {
                    $failed++;
                }
            }
        }

        return compact('indexed', 'failed', 'queued', 'skipped');
    }

    private function shouldQueue(): bool
    {
        return (bool) config('dexter.queue', false);
    }

    private function dispatch(string $scope, int|string $id, ?string $site = null): void
    {
        $job = match ($scope) {
            'entries' => IndexEntryJob::class,
            'categories' => IndexTermJob::class,
            'files' => IndexAssetJob::class,
            'users' => IndexUserJob::class,
            default => null,
        };

        if ($job !== null) {
            dispatch(new $job(array_filter(
                ['id' => $id, 'site' => $site],
                fn ($value) => $value !== null
            )));
        }
    }
}
