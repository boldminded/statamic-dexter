<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Grid: rows of a single fixed field list.
 */
class Grid implements FieldTypeInterface
{
    public function getTypes(): array
    {
        return ['grid'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        if (! is_array($value)) {
            return [];
        }

        $handle = $field->handle();
        $fields = $walker->fieldsFrom($field->config()['fields'] ?? []);
        $out = [];

        foreach (array_values($value) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $out += FieldValue::prefix(
                $handle . '.' . $index,
                $walker->walk($fields, $row)
            );
        }

        return $out;
    }
}
