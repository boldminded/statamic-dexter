<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Relationship fields -- entries, terms, users, taxonomies, collections.
 *
 * Their stored references are indexed as-is. Resolving them to titles would
 * mean augmenting, which hydrates related objects through the Stache and turns
 * a bulk reindex into a cascading N+1.
 */
class Relationship implements FieldTypeInterface
{
    public function getTypes(): array
    {
        return ['entries', 'terms', 'users', 'taxonomies', 'collections'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        return [$field->handle() => FieldValue::scalarList($value)];
    }
}
