<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\Dexter\Service\IndexConfig;
use InvalidArgumentException;

/**
 * The single place a search is performed.
 *
 * The Antlers tags and the public JSON endpoint both route through here, so
 * the allowlist and the result limit cannot be enforced in one and forgotten
 * in the other -- the endpoint is reachable by anyone, and a bypass there
 * would expose indices the site never configured.
 */
class Searcher
{
    public function __construct(
        private IndexConfig $indexConfig,
        private SearchFactory $factory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  string  $index  a configured index key
     * @param  array|string  $params  structured params, or a raw provider filter string
     * @return array<int, array<string, mixed>>
     */
    public function search(
        string $index,
        string $query = '',
        array|string $params = [],
        ?int $limit = null,
    ): array {
        $this->assertConfigured($index);

        $limit = $this->limit($limit, is_array($params) ? $params : []);

        // Meilisearch reads the limit from the params; Algolia takes it as an
        // argument. Set both so the cap holds whichever provider is in use.
        if (is_array($params) && ! isset($params['limit']) && ! isset($params['hitsPerPage'])) {
            $params['limit'] = $limit;
        }

        $provider = $this->factory->make($index);

        if ($provider === null) {
            $this->logger->warning(sprintf('No search provider available for index [%s].', $index));

            return [];
        }

        return $provider->search(
            $this->indexConfig->resolveIndexName($index),
            $query,
            $params,
            $limit,
        );
    }

    /**
     * Federated search across several configured indices.
     *
     * @param  array<int, array<string, mixed>>  $queries  each naming an index
     * @return array<int, array<string, mixed>>
     */
    public function multiSearch(
        array $queries,
        string $query = '',
        array $federation = [],
        ?int $limit = null,
    ): array {
        $resolved = [];
        $provider = null;

        foreach ($queries as $q) {
            $index = $q['index'] ?? $q['indexName'] ?? $q['indexUid'] ?? null;

            // Skip rather than fail: one bad entry should not take out an
            // otherwise valid federated search.
            if ($index === null || ! $this->isConfigured((string) $index)) {
                $this->logger->warning(sprintf(
                    'multiSearch: skipping unconfigured index [%s].',
                    (string) ($index ?? '')
                ));

                continue;
            }

            $provider ??= $this->factory->make((string) $index);

            unset($q['index'], $q['indexName']);
            $q['indexUid'] = $this->indexConfig->resolveIndexName((string) $index);

            $resolved[] = $q;
        }

        if ($resolved === [] || $provider === null) {
            return [];
        }

        $limit = $this->limit($limit);

        // Federation is requested even when the caller asked for none.
        // Meilisearch returns {results: [...]} per query unfederated, but a
        // flat {hits: [...]} when federating -- and core reads `hits`, so an
        // unfederated multi-search silently returns nothing.
        if ($federation === []) {
            $federation = ['limit' => $limit];
        }

        return $provider->multiSearch(
            $resolved,
            $query,
            $federation,
            $limit,
        );
    }

    /**
     * A request may ask for fewer results, never more: an unbounded query
     * against a large index is a cheap way to exhaust memory or run up a
     * provider bill.
     */
    private function limit(?int $requested, array $params = []): int
    {
        $max = $this->indexConfig->searchLimit();
        $requested ??= isset($params['limit']) ? (int) $params['limit'] : null;

        if ($requested === null || $requested < 1) {
            return $max;
        }

        return min($requested, $max);
    }

    public function isConfigured(string $index): bool
    {
        return in_array($index, $this->indexConfig->indexNames(), true);
    }

    private function assertConfigured(string $index): void
    {
        if (! $this->isConfigured($index)) {
            throw new InvalidArgumentException(
                sprintf('Unknown index [%s].', $index)
            );
        }
    }
}
