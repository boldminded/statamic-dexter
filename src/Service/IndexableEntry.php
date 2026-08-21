<?php

namespace BoldMinded\Dexter\Service;

use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Blueprint;

class IndexableEntry extends AbstractIndexable
{
    public function __construct(private Entry $entry)
    {
    }

    public function getScope(): string
    {
        return 'entries';
    }

    public function getTypes(): array
    {
        return ['collection_' . $this->entry->collectionHandle()];
    }

    public function getEntity(): Entry
    {
        return $this->entry;
    }

    public function getId(): int|string
    {
        return $this->entry->id();
    }

    /**
     * Entries are per-site, so the locale is part of identity. Without it a
     * multi-site save would overwrite one localization with another.
     */
    public function getUniqueId(): string
    {
        return 'entry_' . $this->entry->locale() . '_' . $this->entry->id();
    }

    protected function rawData(): array
    {
        return $this->entry->data()->all();
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->entry->blueprint();
    }

    protected function systemValues(): array
    {
        return array_filter([
            'id' => $this->entry->id(),
            // Statamic's own reference format, so the read-only search shim can
            // hand results back to the CP for rehydration.
            'reference' => $this->entry->reference(),
            // Entry has no title() method -- title is ordinary data.
            'title' => $this->entry->get('title'),
            'slug' => $this->entry->slug(),
            'url' => $this->entry->url(),
            'uri' => $this->entry->uri(),
            // Deliberately the without-redirect variant: absoluteUrl() reads
            // the redirect field, which augments and turns a bulk reindex
            // into an N+1. A redirect entry should index its own address
            // anyway -- the target is another entry's business.
            'permalink' => $this->entry->absoluteUrlWithoutRedirect(),
            'collection' => $this->entry->collectionHandle(),
            'site' => $this->entry->locale(),
            'status' => $this->entry->status(),
            'published' => $this->entry->published(),
            'date' => $this->entry->date()?->timestamp,
        ], fn ($value) => $value !== null);
    }

    public function getDefaultFields(): array
    {
        return ['id', 'reference', 'title', 'slug', 'url', 'uri', 'permalink', 'collection', 'site', 'status'];
    }
}
