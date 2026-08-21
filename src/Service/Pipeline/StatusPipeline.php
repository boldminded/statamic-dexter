<?php

namespace BoldMinded\Dexter\Service\Pipeline;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;

/**
 * Drops content whose status is not indexable.
 *
 * Returning an empty array signals the indexer to skip the document. Entries
 * are the only Statamic content type with a status; terms, assets and users
 * pass through untouched.
 *
 * Statamic statuses are published / draft / scheduled / expired. The default
 * indexes published only, so unpublished drafts and expired content never
 * reach a public search index.
 */
class StatusPipeline
{
    private const DEFAULT_STATUSES = ['published'];

    public function __construct(
        private IndexableInterface $indexable,
        private ConfigInterface $config
    ) {
    }

    public function __invoke(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $status = $values['status'] ?? null;

        if ($status === null) {
            return $values;
        }

        $allowed = $this->config->get('statuses');

        if (! is_array($allowed) || $allowed === []) {
            $allowed = self::DEFAULT_STATUSES;
        }

        return in_array($status, $allowed, true) ? $values : [];
    }
}
