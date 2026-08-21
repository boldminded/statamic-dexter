<?php

namespace BoldMinded\Dexter\Service\Fields;

/**
 * Value-shaping helpers shared by the fieldtype handlers.
 *
 * Kept separate from the walker so a handler can flatten or clean a value
 * without holding a reference to the thing that dispatched it.
 */
final class FieldValue
{
    /**
     * Strip markup and decode entities, leaving searchable prose.
     */
    public static function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Flatten a value to its scalar leaves, discarding structure.
     *
     * Used for relationship and asset fields, which store reference strings
     * whose nesting carries no meaning worth indexing.
     *
     * @return array<int, mixed>
     */
    public static function scalarList(mixed $value): array
    {
        if (! is_array($value)) {
            return array_filter([$value], fn ($v) => $v !== null && $v !== '');
        }

        $out = [];

        array_walk_recursive($value, function ($leaf) use (&$out) {
            if (is_scalar($leaf) && $leaf !== '') {
                $out[] = $leaf;
            }
        });

        return $out;
    }

    /**
     * Prefix every key of a flat value set with a parent path.
     *
     * @return array<string, mixed>
     */
    public static function prefix(string $prefix, array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$prefix . '.' . $key] = $value;
        }

        return $out;
    }

    /**
     * Last-resort extraction when a set definition cannot be resolved.
     *
     * Indexes the set's own scalar values so renaming or removing a set type
     * in the blueprint does not silently drop its content from the index.
     *
     * @return array<string, mixed>
     */
    public static function scalarsOnly(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if ($key === 'type' || $key === 'id' || $key === 'enabled') {
                continue;
            }

            if (is_scalar($value)) {
                $out[$key] = is_string($value) ? self::plainText($value) : $value;
            }
        }

        return $out;
    }
}
