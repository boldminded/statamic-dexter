<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Replicator: a flat list of sets, each with a `type` naming its fields.
 *
 * Note the stored shape is flat even when the blueprint groups sets under a
 * `main` heading -- that grouping is Control Panel organisation only.
 */
class Replicator implements FieldTypeInterface
{
    use ResolvesSets;

    public function getTypes(): array
    {
        return ['replicator'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        if (! is_array($value)) {
            return [];
        }

        $handle = $field->handle();
        $out = [];

        foreach (array_values($value) as $index => $set) {
            if (! is_array($set)) {
                continue;
            }

            $setType = $set['type'] ?? null;

            if ($setType === null) {
                continue;
            }

            $out += FieldValue::prefix(
                $handle . '.' . $index,
                $this->setFields($field, $setType, $set, $walker)
            );
        }

        return $out;
    }
}
