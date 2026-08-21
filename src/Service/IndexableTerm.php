<?php

namespace BoldMinded\Dexter\Service;

use Statamic\Contracts\Taxonomies\Term;
use Statamic\Fields\Blueprint;

class IndexableTerm extends AbstractIndexable
{
    public function __construct(private Term $term)
    {
    }

    public function getScope(): string
    {
        return 'categories';
    }

    public function getTypes(): array
    {
        return ['taxonomy_' . $this->term->taxonomyHandle()];
    }

    public function getEntity(): Term
    {
        return $this->term;
    }

    public function getId(): int|string
    {
        return $this->term->id();
    }

    public function getUniqueId(): string
    {
        return 'term_' . $this->term->locale() . '_' . $this->term->taxonomyHandle() . '_' . $this->term->slug();
    }

    protected function rawData(): array
    {
        return $this->term->data()->all();
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->term->blueprint();
    }

    protected function systemValues(): array
    {
        return array_filter([
            'id' => $this->term->id(),
            'reference' => $this->term->reference(),
            'title' => $this->term->title(),
            'slug' => $this->term->slug(),
            'url' => $this->term->url(),
            'uri' => $this->term->uri(),
            // See Entry: the without-redirect variant avoids
            // augmenting during a bulk reindex.
            'permalink' => $this->term->absoluteUrlWithoutRedirect(),
            'taxonomy' => $this->term->taxonomyHandle(),
            'site' => $this->term->locale(),
        ], fn ($value) => $value !== null);
    }

    public function getDefaultFields(): array
    {
        return ['id', 'reference', 'title', 'slug', 'url', 'uri', 'permalink', 'taxonomy', 'site'];
    }
}
