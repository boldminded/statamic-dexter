<?php

namespace BoldMinded\Dexter\Service;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaClient;
use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\DexterCore\Service\Search\Algolia;
use BoldMinded\DexterCore\Service\Search\Meilisearch;
use BoldMinded\DexterCore\Service\Search\SearchProvider;
use BoldMinded\Dexter\Service\IndexConfig;
use Meilisearch\Client as MeilisearchClient;

/**
 * Builds dexter-core's search provider for a configured index.
 *
 * Read-side counterpart to IndexerFactory. Kept separate because core models
 * them separately: searching needs no queue or translator, and an index can
 * be searched by a site that never writes to it.
 */
class SearchFactory
{
    public function __construct(
        private IndexConfig $indexConfig,
        private ConfigInterface $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  string|null  $index  config key; omit to use the first configured index
     */
    public function make(?string $index = null): ?SearchProvider
    {
        $index ??= $this->indexConfig->indexNames()[0] ?? null;

        if ($index === null) {
            return null;
        }

        return match ($this->indexConfig->driverFor($index)) {
            'algolia' => $this->algolia(),
            'meilisearch' => $this->meilisearch(),
            default => null,
        };
    }

    private function algolia(): ?SearchProvider
    {
        $credentials = $this->indexConfig->driverCredentials('algolia');

        if (($credentials['app_id'] ?? '') === '' || ($credentials['secret'] ?? '') === '') {
            $this->logger->warning('Algolia credentials are not configured; search is unavailable.');

            return null;
        }

        return new Algolia(
            AlgoliaClient::create($credentials['app_id'], $credentials['secret']),
            $this->config,
            $this->logger,
        );
    }

    private function meilisearch(): ?SearchProvider
    {
        $credentials = $this->indexConfig->driverCredentials('meilisearch');

        if (($credentials['url'] ?? '') === '') {
            $this->logger->warning('Meilisearch URL is not configured; search is unavailable.');

            return null;
        }

        return new Meilisearch(
            new MeilisearchClient($credentials['url'], $credentials['key'] ?? null),
            $this->config,
            $this->logger,
        );
    }
}
