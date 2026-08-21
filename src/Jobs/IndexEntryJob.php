<?php

namespace BoldMinded\Dexter\Jobs;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\IndexableEntry;
use Statamic\Facades\Entry;

class IndexEntryJob extends AbstractIndexJob
{
    protected function resolve(): ?IndexableInterface
    {
        $entry = Entry::find($this->payload['id'] ?? null);

        return $entry ? new IndexableEntry($entry) : null;
    }
}
