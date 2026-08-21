<?php

namespace BoldMinded\Dexter\Console;

use BoldMinded\Dexter\Service\Indexer\IndexerFactory;
use BoldMinded\Dexter\Service\IndexConfig;
use Illuminate\Console\Command;

/**
 * Removes the index entirely. Distinct from clear: provider-side settings
 * such as synonyms and ranking rules are destroyed with it.
 */
class DeleteCommand extends Command
{
    protected $signature = 'dexter:delete
                            {index : Index to remove entirely}
                            {--force : Skip confirmation}';

    protected $description = 'Delete a Dexter index entirely, including its provider settings';

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

        if (! $this->option('force') && ! $this->confirm("Delete the index [{$label}]?")) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($names as $resolved) {
            $response = $indexers->make($index)->deleteIndex($resolved);

            if ($response->isSuccess()) {
                $this->components->info("Deleted {$resolved}.");

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
