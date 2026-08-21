<?php

namespace BoldMinded\Dexter\Service\Fields;

use Statamic\Fields\Field;

/**
 * Extracts indexable values from one kind of Statamic fieldtype.
 *
 * The Statamic counterpart to the EE build's Service/Field/*FieldType classes
 * and the Craft build's services/elements/* parsers. Each implementation owns
 * one fieldtype's stored shape, so adding support for another is a new class
 * rather than another arm in a growing match statement.
 *
 * Dispatch is on the blueprint's declared field type, never on the shape of
 * the value: Replicator, Grid, Group and Bard sets nest arbitrarily, and a set
 * whose values happen to look like a text node would be mis-handled by shape
 * inspection.
 */
interface FieldTypeInterface
{
    /**
     * The Statamic fieldtype handles this class extracts -- "bard", "grid".
     *
     * @return string[]
     */
    public function getTypes(): array;

    /**
     * Flat, dot-notation values for this field, relative to its own handle.
     *
     * Nested types recurse through the walker rather than calling each other
     * directly, so a set inside a Bard inside a Replicator resolves the same
     * way at every level.
     *
     * @return array<string, mixed>
     */
    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array;
}
