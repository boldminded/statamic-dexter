<?php

namespace BoldMinded\Dexter\Service;

/**
 * Dot-notation field path matching, modelled on dexter-core's Filterable.
 *
 *   `*`                      one path segment
 *   page_builder.*.title  -> page_builder.0.title
 *   page_builder.*.image  -> page_builder.0.image AND page_builder.0.image.0
 *
 * A rule names a field, and matches that field plus everything beneath it.
 * That matters because Statamic fieldtypes differ in whether they store a
 * scalar or a list -- an assets field holding one image still stores a
 * one-element array -- and config authors should not have to know which is
 * which. Matching is anchored at segment boundaries, so `title` never
 * matches `title_extra` and `image` never matches `secondary_image`.
 *
 * The path syntax is unchanged from the ExpressionEngine and Craft builds;
 * only the surrounding config structure differs per platform.
 */
class FieldMatcher
{
    /**
     * Flatten nested data into dot-notation paths.
     */
    public static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * Keep only the flattened paths matching one of the configured rules.
     */
    public static function filter(array $flat, array $rules): array
    {
        if ($rules === []) {
            return $flat;
        }

        $patterns = array_map(fn (string $rule) => self::toRegex($rule), $rules);

        return array_filter(
            $flat,
            function (string $path) use ($patterns): bool {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        return true;
                    }
                }

                return false;
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Rebuild dot-notation paths into a nested array.
     *
     * Numeric segments become list indices, so `page_builder.0.title` returns
     * to a real array rather than a key literally named "0". Indices are
     * re-sequenced because filtering can leave gaps, and a gapped PHP array
     * encodes as a JSON object instead of an array.
     */
    public static function expand(array $flat): array
    {
        $out = [];

        foreach ($flat as $path => $value) {
            $segments = explode('.', (string) $path);
            $cursor = &$out;

            foreach ($segments as $segment) {
                if (! is_array($cursor)) {
                    $cursor = [];
                }

                if (! array_key_exists($segment, $cursor)) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            $cursor = $value;
            unset($cursor);
        }

        return self::resequence($out);
    }

    /**
     * Collapse arrays whose keys are all numeric back into lists.
     */
    private static function resequence(array $values): array
    {
        $allNumeric = $values !== [];

        foreach (array_keys($values) as $key) {
            if (! is_int($key) && ! ctype_digit((string) $key)) {
                $allNumeric = false;
                break;
            }
        }

        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = is_array($value) ? self::resequence($value) : $value;
        }

        return $allNumeric ? array_values($out) : $out;
    }

    /**
     * Compile a configured path into a regex.
     *
     * Every rule matches the named path *and everything beneath it*. A rule
     * names a field, and a field's value may be a scalar, a list, or a nested
     * structure -- `page_builder.*.image` must capture `page_builder.0.image.0`
     * because an assets field normalises to a list, and the author writing
     * that rule should not have to know which fieldtypes store arrays.
     */
    public static function toRegex(string $allowedPath): string
    {
        // Trailing ".*" is redundant now that every rule includes descendants,
        // but remains valid so existing config keeps working.
        if (str_ends_with($allowedPath, '.*')) {
            $allowedPath = substr($allowedPath, 0, -2);
        }

        // Any other "*" is a single-segment wildcard.
        $pattern = preg_quote($allowedPath, '/');
        $pattern = str_replace('\*', '[^.]+', $pattern);

        // (\..+)? -- the path itself, or any descendant of it.
        return '/^' . $pattern . '(\..+)?$/';
    }
}
