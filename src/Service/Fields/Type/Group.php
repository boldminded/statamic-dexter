<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Group: a single nested field list, no repetition.
 */
class Group implements FieldTypeInterface
{
    public function getTypes(): array
    {
        return ['group'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fields = $walker->fieldsFrom($field->config()['fields'] ?? []);

        return FieldValue::prefix(
            $field->handle(),
            $walker->walk($fields, $value)
        );
    }
}
