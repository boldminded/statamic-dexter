<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldTypeInterface;
use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Markdown: stored as raw markdown, indexed as prose.
 *
 * The stored value is not HTML, but plainText() still applies: markdown
 * commonly embeds inline HTML, and entities appear in both.
 */
class Markdown implements FieldTypeInterface
{
    public function getTypes(): array
    {
        return ['markdown'];
    }

    public function extract(Field $field, mixed $value, FieldValueWalker $walker): array
    {
        return [$field->handle() => FieldValue::plainText((string) $value)];
    }
}
