<?php

namespace BoldMinded\Dexter\Tags;

use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\Dexter\Service\Searcher;
use Statamic\Tags\Tags;

/**
 * {{ dexter:search }} and {{ dexter:multi_search }}.
 *
 * Results are returned as plain arrays from the search provider, so templates
 * read indexed values directly. Nothing is rehydrated from the Stache: the
 * point of the index is that a search does not have to touch content storage.
 */
class DexterTags extends Tags
{
    protected static $handle = 'dexter';

    /**
     * {{ dexter:search index="content" query="pizza" }}
     */
    public function search(): array|string
    {
        $index = (string) ($this->params->get('index') ?? '');
        $query = (string) ($this->params->get('query') ?? $this->params->get('term') ?? '');

        if ($index === '') {
            $this->logger()->warning('{{ dexter:search }} was called without an index.');

            return $this->noResults();
        }

        try {
            $results = $this->searcher()->search(
                $index,
                $query,
                $this->searchParams(),
                $this->params->int('limit') ?: null,
            );
        } catch (\Throwable $e) {
            // A search provider being unreachable should not take the page
            // down; the template renders its no-results branch instead.
            $this->logger()->error('{{ dexter:search }} failed: ' . $e->getMessage());

            return $this->noResults();
        }

        return $this->results($results);
    }

    /**
     * {{ dexter:multi_search indexes="content|menu_items" query="pizza" }}
     */
    public function multiSearch(): array|string
    {
        $indexes = $this->indexList();
        $query = (string) ($this->params->get('query') ?? $this->params->get('term') ?? '');

        if ($indexes === []) {
            $this->logger()->warning('{{ dexter:multi_search }} was called without any indexes.');

            return $this->noResults();
        }

        $queries = array_map(fn (string $index) => ['index' => $index], $indexes);

        try {
            $results = $this->searcher()->multiSearch(
                $queries,
                $query,
                $this->federation(),
                $this->params->int('limit') ?: null,
            );
        } catch (\Throwable $e) {
            $this->logger()->error('{{ dexter:multi_search }} failed: ' . $e->getMessage());

            return $this->noResults();
        }

        return $this->results($results);
    }

    /**
     * Provider-specific parameters, from a JSON `params` attribute or a raw
     * filter string.
     *
     * A malformed value is logged and dropped rather than thrown: a typo in a
     * template should degrade the search, not white-screen the page.
     */
    private function searchParams(): array|string
    {
        $raw = $this->params->get('params') ?? $this->params->get('filter');

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Not JSON. Algolia accepts a raw SQL-like filter string, so pass it
        // through rather than discarding a deliberate value.
        if ($this->params->get('filter') !== null) {
            return (string) $raw;
        }

        $this->logger()->warning(sprintf(
            '{{ dexter:search }} could not parse params: %s',
            json_last_error_msg()
        ));

        return [];
    }

    private function federation(): array
    {
        $raw = $this->params->get('federation');

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return string[]
     */
    private function indexList(): array
    {
        $raw = $this->params->get('indexes') ?? $this->params->get('index') ?? '';

        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        return array_values(array_filter(array_map(
            'trim',
            explode('|', (string) $raw)
        )));
    }

    /**
     * Hand results to Antlers.
     *
     * parseLoop rather than returning the array: provider hits are nested
     * arrays, and returning them raw leaves Antlers with nothing to iterate,
     * so the tag pair renders empty.
     */
    private function results(array $results): array|string
    {
        if ($results === []) {
            return $this->noResults();
        }

        return $this->parseLoop($results);
    }

    private function noResults(): string
    {
        // Antlers convention: a tag pair renders its {{ if no_results }}
        // branch when the tag yields nothing.
        return $this->parse(['no_results' => true, 'results' => []]);
    }

    private function searcher(): Searcher
    {
        return app(Searcher::class);
    }

    private function logger(): LoggerInterface
    {
        return app(LoggerInterface::class);
    }
}
