<?php

namespace BoldMinded\Dexter\Service\Indexer;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\DexterCore\Service\Indexer\DeleteCategoryCommand;
use BoldMinded\DexterCore\Service\Indexer\DeleteCommand;
use BoldMinded\DexterCore\Service\Indexer\DeleteEntryCommand;
use BoldMinded\DexterCore\Service\Indexer\DeleteFileCommand;
use BoldMinded\DexterCore\Service\Indexer\DeleteUserCommand;
use BoldMinded\DexterCore\Service\Indexer\IndexerResponse;

/**
 * The single entry point for writing to and removing from a search index.
 *
 * Event listeners, queued jobs and console commands all route through here so
 * index-name resolution, provider selection and error handling live in one
 * place.
 */
class Indexer
{
    public function __construct(
        private CommandFactory $commands,
        private IndexerFactory $indexers,
        private ConfigInterface $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The configured index for this item, or null when its source is not
     * configured for indexing.
     */
    public function indexNameFor(IndexableInterface $indexable): ?string
    {
        return $this->commands->indexNameFor($indexable);
    }

    /**
     * Index one item. Returns null when its source is not configured, which
     * is the normal case for content the site has not opted in to indexing.
     */
    public function index(IndexableInterface $indexable, bool $queue = true): ?IndexerResponse
    {
        $indexName = $this->commands->indexNameFor($indexable);

        if ($indexName === null) {
            return null;
        }

        $command = $this->commands->make($indexable, $this->queueJobFor($indexable));

        if ($command === null) {
            return null;
        }

        $response = $this->indexers
            ->make($this->commands->indexKeyFor($indexable) ?? $indexName)
            ->single($command, $queue);

        if (! $response->isSuccess()) {
            $this->logger->error(sprintf(
                'Failed to index %s [%s]: %s',
                $indexable->getScope(),
                $indexable->getUniqueId(),
                implode('; ', $response->getErrors())
            ));
        }

        return $response;
    }

    /**
     * Remove one item from its configured index.
     */
    public function delete(IndexableInterface $indexable, bool $queue = true): ?IndexerResponse
    {
        $indexName = $this->commands->indexNameFor($indexable);

        if ($indexName === null) {
            return null;
        }

        $command = $this->deleteCommand($indexable, $indexName);

        if ($command === null) {
            return null;
        }

        $response = $this->indexers
            ->make($this->commands->indexKeyFor($indexable) ?? $indexName)
            ->delete($command, $queue);

        if (! $response->isSuccess()) {
            $this->logger->error(sprintf(
                'Failed to delete %s [%s]: %s',
                $indexable->getScope(),
                $indexable->getUniqueId(),
                implode('; ', $response->getErrors())
            ));
        }

        return $response;
    }

    /**
     * Core's delete commands rebuild their own unique id from an integer site
     * id, a shape inherited from ExpressionEngine. Statamic sites are string
     * handles, so DeleteAdapter overrides getUniqueId() to return the
     * indexable's own id and keep index and delete in agreement.
     */
    private function deleteCommand(IndexableInterface $indexable, string $indexName): ?DeleteCommand
    {
        $title = (string) ($indexable->get('title') ?? $indexable->get('name') ?? $indexable->getId());

        $inner = match ($indexable->getScope()) {
            'entries' => new DeleteEntryCommand($indexName, $indexable->getId(), 0, $title, ''),
            'categories' => new DeleteCategoryCommand($indexName, $indexable->getId(), 0, $title, ''),
            'files' => new DeleteFileCommand($indexName, $indexable->getId(), 0, $title, ''),
            'users' => new DeleteUserCommand($indexName, $indexable->getId(), 0, $title, ''),
            default => null,
        };

        return $inner === null ? null : new DeleteAdapter($inner, $indexable->getUniqueId());
    }

    private function queueJobFor(IndexableInterface $indexable): string
    {
        return match ($indexable->getScope()) {
            'entries' => \BoldMinded\Dexter\Jobs\IndexEntryJob::class,
            'categories' => \BoldMinded\Dexter\Jobs\IndexTermJob::class,
            'files' => \BoldMinded\Dexter\Jobs\IndexAssetJob::class,
            'users' => \BoldMinded\Dexter\Jobs\IndexUserJob::class,
            default => '',
        };
    }
}
