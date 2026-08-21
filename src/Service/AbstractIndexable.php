<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\Fields\BlueprintField;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Blueprint;

/**
 * Shared behaviour for the Statamic implementations of core's
 * IndexableInterface.
 *
 * Subclasses supply the entity, its scope and type, and the raw stored data.
 * Everything else -- blueprint field discovery and flattening nested values --
 * is common.
 */
abstract class AbstractIndexable implements IndexableInterface
{
    private ?array $flattened = null;

    abstract public function getScope(): string;

    abstract public function getTypes(): array;

    abstract public function getEntity();

    abstract public function getId(): int|string;

    abstract public function getUniqueId(): string;

    /**
     * Raw stored data, before flattening. Never augmented: augmentation
     * hydrates related objects through the Stache and turns a bulk reindex
     * into a cascading N+1.
     */
    abstract protected function rawData(): array;

    abstract protected function blueprint(): ?Blueprint;

    /**
     * Values common to every item of this scope, merged over field data.
     */
    protected function systemValues(): array
    {
        return [];
    }

    public function get(string $key): mixed
    {
        return $this->getValues()[$key] ?? null;
    }

    /**
     * Flat, dot-notation keyed values ready for the field map to filter.
     */
    public function getValues(): array
    {
        if ($this->flattened !== null) {
            return $this->flattened;
        }

        $raw = $this->rawData();
        $blueprint = $this->blueprint();

        // Resolved rather than constructed: a bulk reindex builds one
        // indexable per item, and a fresh walker each time would rebuild the
        // whole handler set for every document.
        $walker = app()->bound(FieldValueWalker::class)
            ? app(FieldValueWalker::class)
            : new FieldValueWalker();

        $values = [];

        if ($blueprint !== null) {
            foreach ($blueprint->fields()->all() as $field) {
                $handle = $field->handle();

                if (! array_key_exists($handle, $raw)) {
                    continue;
                }

                $values += $walker->extract($field, $raw[$handle]);
            }
        }

        // Data present without a blueprint field backing it, so nothing is
        // silently dropped when a blueprint and its content drift apart.
        foreach ($raw as $key => $value) {
            if (! array_key_exists($key, $values) && is_scalar($value)) {
                $values[$key] = $value;
            }
        }

        return $this->flattened = array_merge($values, $this->systemValues());
    }

    /**
     * @return BlueprintField[] keyed by handle
     */
    public function getCustomFields(): array
    {
        $blueprint = $this->blueprint();

        if ($blueprint === null) {
            return [];
        }

        $raw = $this->rawData();
        $fields = [];

        foreach ($blueprint->fields()->all() as $field) {
            $fields[$field->handle()] = new BlueprintField(
                $field,
                $raw[$field->handle()] ?? null
            );
        }

        return $fields;
    }

    public function getRelated(string $type): array
    {
        return [];
    }

    /**
     * Fields always indexed for this scope, regardless of the field map.
     */
    public function getDefaultFields(): array
    {
        return [];
    }
}
