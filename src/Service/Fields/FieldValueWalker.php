<?php

namespace BoldMinded\Dexter\Service\Fields;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Converts raw Statamic field data into flat, indexable values.
 *
 * Replaces the former ValueExtractor. The per-fieldtype knowledge now lives in
 * FieldTypeInterface implementations resolved through FieldTypeFactory; what
 * remains here is dispatch, recursion into nested field lists, and the scalar
 * fallback for the many fieldtypes needing no special handling.
 *
 * Two rules drive this class and its handlers:
 *
 * 1. Recursion is dispatched on the *blueprint field type*, never on the shape
 *    of the value. Replicator, Grid, Group and Bard sets nest arbitrarily, and
 *    a set whose fields happen to look like a text node would be mis-handled by
 *    shape inspection.
 *
 * 2. Nothing here augments. Statamic's augmentation hydrates related objects
 *    through the Stache, which turns a large reindex into a cascading N+1.
 *    Relationship fields keep their stored references; resolving them to titles
 *    is a separate, opt-in concern.
 */
class FieldValueWalker
{
    /**
     * Field types carrying no indexable text.
     */
    private const SKIP = [
        'section', 'spacer', 'revealer', 'width', 'hidden',
        'template', 'icon', 'color', 'range', 'button_group',
    ];

    private FieldTypeFactory $types;

    /**
     * The factory is optional so a walker stays newable without a container --
     * unit tests do exactly that. Resolved from the container when one is
     * available, so the handler set is built once rather than per field.
     */
    public function __construct(?FieldTypeFactory $types = null)
    {
        $this->types = $types ?? (
            app()->bound(FieldTypeFactory::class)
                ? app(FieldTypeFactory::class)
                : new FieldTypeFactory()
        );
    }

    /**
     * Extract indexable values for one field.
     *
     * @return array<string, mixed> keyed by dot-notation path, relative to the field handle
     */
    public function extract(Field $field, mixed $value): array
    {
        $type = $field->type();

        if (in_array($type, self::SKIP, true) || $value === null || $value === '') {
            return [];
        }

        $handler = $this->types->make($type);

        if ($handler !== null) {
            return $handler->extract($field, $value, $this);
        }

        return $this->scalar($field->handle(), $value);
    }

    /**
     * Extract every field in a definition list against a values array.
     *
     * The recursion point for the nested handlers: Grid rows, Group values and
     * Replicator or Bard sets all resolve their own field list and hand it
     * back here.
     *
     * @return array<string, mixed>
     */
    public function walk(Fields $fields, array $values): array
    {
        $out = [];

        foreach ($fields->all() as $field) {
            $handle = $field->handle();

            if (! array_key_exists($handle, $values)) {
                continue;
            }

            $out += $this->extract($field, $values[$handle]);
        }

        return $out;
    }

    /**
     * Build a Fields collection from a raw blueprint field-list config.
     */
    public function fieldsFrom(array $config): Fields
    {
        return new Fields($config);
    }

    /**
     * The fallback for fieldtypes with no handler: index the value as-is,
     * cleaning strings and flattening arrays to their scalar leaves.
     *
     * @return array<string, mixed>
     */
    private function scalar(string $handle, mixed $value): array
    {
        if (is_array($value)) {
            return [$handle => FieldValue::scalarList($value)];
        }

        if (is_bool($value)) {
            return [$handle => $value];
        }

        return [$handle => is_string($value) ? FieldValue::plainText($value) : $value];
    }
}
