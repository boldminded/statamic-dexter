<?php

namespace BoldMinded\Dexter\Tests\Feature;

use BoldMinded\DexterCore\Service\Indexer\CommandCollection;
use BoldMinded\DexterCore\Service\Indexer\DeleteCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexerResponse;
use BoldMinded\DexterCore\Service\Indexer\IndexProvider;

/**
 * Stands in for Meilisearch/Algolia so the CP action tests never touch a live
 * provider. Records the index names it was called with, so tests can assert
 * the controller passes the *resolved* name rather than the config key.
 */
class FakeIndexProvider implements IndexProvider
{
    public array $exportedWith = [];
    public array $importedWith = [];
    public array $clearedWith = [];
    public array $deletedIndexes = [];

    /**
     * @param  array<string, int>  $indexes  resolved index name => document count,
     *                                       as the provider would report them
     */
    public function __construct(
        private array $settings = ['searchableAttributes' => ['title']],
        private bool $importSucceeds = true,
        private ?\Throwable $exportThrows = null,
        private array $indexes = [],
    ) {
    }

    public function export(string $indexName): array
    {
        $this->exportedWith[] = $indexName;

        if ($this->exportThrows !== null) {
            throw $this->exportThrows;
        }

        return $this->settings;
    }

    public function import(string $indexName, array $settings): bool
    {
        $this->importedWith[] = ['index' => $indexName, 'settings' => $settings];

        return $this->importSucceeds;
    }

    public function clear(string $indexName): IndexerResponse
    {
        $this->clearedWith[] = $indexName;

        return new IndexerResponse();
    }

    public function deleteIndex(string $indexName): IndexerResponse
    {
        $this->deletedIndexes[] = $indexName;

        return new IndexerResponse();
    }

    public function single(IndexCommand $command, bool $shouldQueue = true): IndexerResponse
    {
        return new IndexerResponse();
    }

    public function bulk(CommandCollection $collection): IndexerResponse
    {
        return new IndexerResponse();
    }

    public function delete(DeleteCommand $command, bool $shouldQueue = true): IndexerResponse
    {
        return new IndexerResponse();
    }

    /**
     * The shape DexterController::available() reads: one row per index the
     * provider holds, whether or not this site configures it.
     */
    public function list(): array
    {
        $rows = [];

        foreach ($this->indexes as $name => $documents) {
            $rows[] = [
                'indexName' => $name,
                'documentCount' => $documents,
                'totalSize' => $documents * 1024,
                'avgSize' => $documents > 0 ? 1024 : 0,
            ];
        }

        return $rows;
    }
}
