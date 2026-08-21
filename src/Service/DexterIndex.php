<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\LoggerInterface;
use BoldMinded\Dexter\Service\IndexConfig;
use LogicException;
use Statamic\Search\Documents;
use Statamic\Search\Index;
use Statamic\Search\Result;

/**
 * Read-only Statamic search driver backed by a Dexter index.
 *
 * Lets {{ search }}, the Search facade and the Control Panel query an index
 * Dexter built, without handing Statamic any control over its contents.
 * Dexter owns writing: its field maps, document parsing and AI enrichment
 * have no equivalent in Statamic's flat searchable model, so a write arriving
 * through this driver would quietly replace a rich document with a poor one.
 *
 * Configure in config/statamic/search.php:
 *
 *     'indexes' => [
 *         'content' => ['driver' => 'dexter', 'dexter_index' => 'content'],
 *     ],
 */
class DexterIndex extends Index
{
    /**
     * Statamic's index name, and Dexter's, are separate namespaces. The
     * `dexter_index` config key maps one to the other; without it the two
     * names are assumed to match.
     */
    public function dexterIndex(): string
    {
        return $this->config['dexter_index'] ?? $this->name;
    }

    public function search($query)
    {
        return (new DexterQuery($this))->query($query);
    }

    /**
     * Run the query against the Dexter index.
     *
     * @return array<int, array<string, mixed>>
     */
    public function performSearch(string $query, ?int $limit = null): array
    {
        $index = $this->dexterIndex();

        if (! $this->searcher()->isConfigured($index)) {
            $this->logger()->warning(sprintf(
                'Statamic search index [%s] points at unknown Dexter index [%s].',
                $this->name,
                $index
            ));

            return [];
        }

        return $this->searcher()->search($index, $query, [], $limit);
    }

    /**
     * Whether the underlying Dexter index exists.
     *
     * Reported honestly and never optimistically. Statamic calls update() on
     * any index that reports false -- from ensureExists(), and from
     * updateWithinIndexes() during an ordinary content save. update() here
     * refuses, so a false negative turns every content save into a hard
     * error. Getting this right is what makes the shim safe to configure.
     */
    public function exists()
    {
        $index = $this->dexterIndex();

        if (! $this->searcher()->isConfigured($index)) {
            return false;
        }

        $provider = app(\BoldMinded\Dexter\Service\Indexer\IndexerFactory::class)->make($index);

        if ($provider instanceof \BoldMinded\DexterCore\Service\Indexer\Dummy) {
            return false;
        }

        $resolved = app(IndexConfig::class)->resolveIndexName($index);

        try {
            foreach ($provider->list() as $stat) {
                if (($stat['indexName'] ?? null) === $resolved) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger()->warning(sprintf(
                'Could not reach the provider for [%s]: %s',
                $resolved,
                $e->getMessage()
            ));
        }

        return false;
    }

    // Writes.
    //
    // Statamic drives two of these on every content save, from
    // updateWithinIndexes(): insert() when the index exists, delete() when the
    // content no longer belongs in it. Throwing there would make saving
    // content impossible whenever a Dexter-driven index is configured, so
    // routine writes are ignored -- Dexter's own save listener has already
    // indexed the item, through the field map and pipelines that give the
    // document its value.
    //
    // The destructive operations still refuse, because those only happen when
    // something deliberately asks for them, and silently doing nothing would
    // let an operator believe an index had been rebuilt when it had not.

    public function insertDocuments(Documents $documents)
    {
        $this->ignoredWrite('insert');

        return $this;
    }

    public function delete($document)
    {
        $this->ignoredWrite('delete');

        return $this;
    }

    public function update()
    {
        throw new LogicException($this->writeRefusal('rebuild'));
    }

    protected function deleteIndex()
    {
        throw new LogicException($this->writeRefusal('delete'));
    }

    private function ignoredWrite(string $operation): void
    {
        $this->logger()->debug(sprintf(
            'Ignored a Statamic %s on the Dexter-owned index [%s]; Dexter maintains it.',
            $operation,
            $this->dexterIndex()
        ));
    }

    /**
     * Surface anything the Dexter document carries that the rehydrated
     * Statamic object does not -- AI-generated descriptions and tags in
     * particular, which exist in the index and nowhere else.
     */
    public function extraAugmentedResultData(Result $result)
    {
        $raw = $result->getRawResult();

        return array_filter(
            $raw,
            fn (string $key) => ! in_array($key, ['reference', 'search_score'], true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function writeRefusal(string $verb): string
    {
        return sprintf(
            'Dexter owns the [%s] index; Statamic cannot %s it. Use the Dexter '
            . 'control panel or php please dexter:reindex instead.',
            $this->dexterIndex(),
            $verb
        );
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
