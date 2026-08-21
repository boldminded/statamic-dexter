<?php

namespace BoldMinded\Dexter\Console;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Service\IndexConfig;
use Illuminate\Console\Command;

/**
 * Empties an index without removing it, so searches return nothing rather
 * than erroring while a reindex runs.
 */
class ClearCommand extends Command
{
    protected $signature = 'dexter:clear
                            {index : Index to empty}
                            {--force : Skip confirmation}';

    protected $description = 'Remove all documents from a Dexter index';

    public function handle(IndexConfig $config, IndexerFactory $indexers): int
    {
        $index = $this->argument('index');

        if (! in_array($index, $config->indexNames(), true)) {
            $this->components->error(
                __('dexter::messages.unknown_index', ['index' => $index])
            );

            return self::FAILURE;
        }

        // Every name the key maps to: a localized index has one per site,
        // and clearing only the first would leave the others populated while
        // reporting success.
        $names = $config->resolveIndexNames($index);
        $label = implode(', ', $names);

        if (! $this->option('force') && ! $this->confirm("Remove all documents from [{$label}]?")) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($names as $resolved) {
            $response = $indexers->make($index)->clear($resolved);

            if ($response->isSuccess()) {
                $this->components->info("Cleared {$resolved}.");

                continue;
            }

            // Reported, but the remaining sites are still attempted: one
            // unreachable locale should not strand the rest.
            $this->components->error($resolved . ': ' . implode('; ', $response->getErrors()));
            $failed = true;
        }

        if ($failed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
