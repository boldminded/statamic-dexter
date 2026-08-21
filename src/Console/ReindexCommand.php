<?php

namespace BoldMinded\Dexter\Console;

use BoldMinded\Dexter\Service\Indexer\Reindexer;
use BoldMinded\Dexter\Service\IndexConfig;
use Illuminate\Console\Command;

class ReindexCommand extends Command
{
    protected $signature = 'dexter:reindex
                            {index? : Index to rebuild; omit to rebuild all configured indexes}
                            {--searchable= : Restrict to one searchable, e.g. collection:pages}
                            {--queue : Dispatch queued jobs instead of indexing inline}';

    protected $description = 'Rebuild a Dexter search index';

    public function handle(Reindexer $reindexer, IndexConfig $config): int
    {
        if ($this->option('queue')) {
            config(['dexter.queue' => true]);
        }

        $indexes = $this->argument('index') !== null
            ? [$this->argument('index')]
            : $config->indexNames();

        if ($indexes === []) {
            $this->components->warn('No indexes are configured.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($indexes as $index) {
            // Validate before announcing, so an unknown index does not print
            // a reassuring "Reindexing ..." line it is about to contradict.
            if (! in_array($index, $config->indexNames(), true)) {
                $this->components->error(
                    __('dexter::messages.unknown_index', ['index' => $index])
                );
                $failed++;
                continue;
            }

            $this->newLine();
            $this->components->info(sprintf(
                'Reindexing %s → %s',
                $index,
                $config->resolveIndexName($index)
            ));

            try {
                $result = $reindexer->run(
                    $index,
                    $this->option('searchable'),
                    function (string $key, string $uniqueId) {
                        if ($this->output->isVerbose()) {
                            $this->line("  <fg=gray>{$key}</> {$uniqueId}");
                        }
                    }
                );
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());
                $failed++;
                continue;
            }

            foreach ($result['skipped'] as $skipped) {
                $this->components->warn($skipped);
            }

            if ($result['queued'] > 0) {
                $this->components->info(sprintf('%d job(s) queued.', $result['queued']));
            }

            if ($result['indexed'] > 0 || $result['failed'] > 0) {
                $this->components->info(sprintf(
                    '%d indexed, %d failed.',
                    $result['indexed'],
                    $result['failed']
                ));
            }

            // A failed write means the document is missing from the index, so
            // the run is not a success even though it completed.
            $failed += $result['failed'];
        }

        $this->newLine();

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
