<?php

namespace BoldMinded\Dexter\Service\Pipeline;

use BoldMinded\DexterCore\Contracts\ConfigInterface;
use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\FieldMatcher;

/**
 * Reduces an indexable's values to those the configured field map allows.
 *
 * This is the Statamic counterpart to the EE build's CustomFieldsPipeline,
 * minus its reindexFields() step: Statamic handles are already names, so there
 * is no field-id-to-short-name mapping to reverse. The blueprint walking that
 * pipeline did for EE happens earlier here, in FieldValueWalker.
 */
class FieldMapPipeline
{
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

        $rules = $this->rules();

        if ($rules === []) {
            return $values;
        }

        $filtered = FieldMatcher::filter(FieldMatcher::flatten($values), $rules);

        return FieldMatcher::expand($filtered);
    }

    /**
     * Field map for this indexable's scope and type, read through core's
     * Config so index-time behaviour matches what dexter:preview shows.
     */
    private function rules(): array
    {
        $scope = $this->indexable->getScope();
        $rules = $this->config->get('indexableFields.' . $scope) ?? [];

        foreach ($this->indexable->getTypes() as $type) {
            $rules = array_merge(
                $rules,
                $this->config->get('indexableFields.' . $scope . '[' . $type . ']') ?? []
            );
        }

        if ($rules === []) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $this->defaultFields(),
            $rules
        )));
    }

    /**
     * Identity fields are always indexed. Without them a document cannot be
     * linked back to its source content.
     */
    private function defaultFields(): array
    {
        return method_exists($this->indexable, 'getDefaultFields')
            ? $this->indexable->getDefaultFields()
            : [];
    }
}
