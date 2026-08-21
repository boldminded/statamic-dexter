<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Bard has three stored shapes:
 *
 *   - an HTML string, when save_html is enabled
 *   - an array of ProseMirror nodes
 *   - those nodes including `type: set` items carrying their own fields
 */
class Bard implements FieldTypeInterface
{
    use ResolvesSets;

    public function getTypes(): array
    {
        return ['bard'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        $handle = $field->handle();

        if (is_string($value)) {
            return [$handle => FieldValue::plainText($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $text = [];
        $out = [];
        $setIndex = 0;

        foreach ($value as $node) {
            if (! is_array($node)) {
                continue;
            }

            // A Bard set is a nested field container, handled like a
            // replicator set rather than as prose.
            if (($node['type'] ?? null) === 'set') {
                $attrs = $node['attrs']['values'] ?? [];
                $setType = $attrs['type'] ?? null;

                if ($setType !== null) {
                    $out += FieldValue::prefix(
                        $handle . '.' . $setIndex,
                        $this->setFields($field, $setType, $attrs, $walker)
                    );
                }

                $setIndex++;
                continue;
            }

            $collected = $this->proseMirrorText($node);

            if ($collected !== '') {
                $text[] = $collected;
            }
        }

        if ($text !== []) {
            $out[$handle] = implode(' ', $text);
        }

        return $out;
    }

    /**
     * Walk a ProseMirror node tree collecting text nodes.
     */
    private function proseMirrorText(array $node): string
    {
        $parts = [];

        if (($node['type'] ?? null) === 'text' && isset($node['text'])) {
            $parts[] = (string) $node['text'];
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $collected = $this->proseMirrorText($child);

                if ($collected !== '') {
                    $parts[] = $collected;
                }
            }
        }

        return implode(' ', $parts);
    }
}
