<?php

namespace BoldMinded\Dexter\Service;

use Statamic\Search\QueryBuilder;

class DexterQuery extends QueryBuilder
{
    /**
     * Statamic rehydrates results by their `reference`, which Dexter indexes
     * as a document field precisely so this shim can work. A hit without one
     * is dropped rather than passed on -- Statamic would fail to resolve it
     * and take the whole result set down.
     */
    public function getSearchResults($query)
    {
        $results = collect($this->index->performSearch($query));

        return $results
            ->filter(fn ($hit) => ! empty($hit['reference']))
            ->values()
            ->map(function ($hit, $i) use ($results) {
                // Providers return hits already ranked, but not all of them
                // return a numeric score. Descending position preserves the
                // provider's ordering through Statamic's own sort.
                $hit['search_score'] ??= $results->count() - $i;

                return $hit;
            });
    }
}
