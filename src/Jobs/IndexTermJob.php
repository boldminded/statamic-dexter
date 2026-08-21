<?php

namespace BoldMinded\Dexter\Jobs;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\IndexableTerm;
use Statamic\Facades\Term;

class IndexTermJob extends AbstractIndexJob
{
    /**
     * A term id is shared across localizations, so Term::find() hands back the
     * default one. Without re-applying the locale a queued rebuild of another
     * site would index the default term's values under that site's index.
     */
    protected function resolve(): ?IndexableInterface
    {
        $term = Term::find($this->payload['id'] ?? null);

        if ($term === null) {
            return null;
        }

        $site = $this->payload['site'] ?? null;

        if (is_string($site) && $site !== '') {
            $term = $term->in($site) ?? $term;
        }

        return new IndexableTerm($term);
    }
}
