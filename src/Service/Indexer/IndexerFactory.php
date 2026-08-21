<?php

namespace BoldMinded\Dexter\Service\Indexer;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaClient;
use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\DexterCore\Contracts\QueueInterface;
use BoldMinded\DexterCore\Contracts\TranslatorInterface;
use BoldMinded\DexterCore\Service\Indexer\Algolia;
use BoldMinded\DexterCore\Service\Indexer\Dummy;
use BoldMinded\DexterCore\Service\Indexer\IndexProvider;
use BoldMinded\DexterCore\Service\Indexer\Meilisearch;
use BoldMinded\Dexter\Service\IndexConfig;
use Meilisearch\Client as MeilisearchClient;

/**
 * Builds dexter-core's index provider for the configured driver.
 *
 * Falls back to core's Dummy provider when credentials are missing, so a
 * misconfigured site logs and no-ops rather than throwing on every save.
 */
class IndexerFactory
{
    public function __construct(
        private IndexConfig $indexConfig,
        private ConfigInterface $config,
        private LoggerInterface $logger,
        private QueueInterface $queue,
        private TranslatorInterface $translator,
    ) {
    }

    public function make(string $indexName): IndexProvider
    {
        $driver = $this->indexConfig->driverFor($indexName);

        return match ($driver) {
            'algolia' => $this->algolia(),
            'meilisearch' => $this->meilisearch(),
            default => $this->dummy($driver),
        };
    }

    public function shouldQueue(): bool
    {
        return (bool) config('dexter.queue', false);
    }

    private function algolia(): IndexProvider
    {
        $credentials = $this->indexConfig->driverCredentials('algolia');
        $appId = $credentials['app_id'] ?? '';
        $secret = $credentials['secret'] ?? '';

        if ($appId === '' || $secret === '') {
            $this->logger->warning('Algolia credentials are not configured; indexing is disabled.');

            return new Dummy();
        }

        return new Algolia(
            AlgoliaClient::create($appId, $secret),
            $this->config,
            $this->logger,
            $this->queue,
            $this->translator,
            $this->shouldQueue(),
        );
    }

    private function meilisearch(): IndexProvider
    {
        $credentials = $this->indexConfig->driverCredentials('meilisearch');
        $url = $credentials['url'] ?? '';

        if ($url === '') {
            $this->logger->warning('Meilisearch URL is not configured; indexing is disabled.');

            return new Dummy();
        }

        return new Meilisearch(
            new MeilisearchClient($url, $credentials['key'] ?? null),
            $this->config,
            $this->logger,
            $this->queue,
            $this->translator,
            $this->shouldQueue(),
        );
    }

    private function dummy(?string $driver): IndexProvider
    {
        if ($driver !== null && $driver !== '') {
            $this->logger->warning(sprintf('Unknown Dexter driver [%s].', $driver));
        }

        return new Dummy();
    }
}
