<?php

namespace BoldMinded\Dexter\Service\Fields;

use BoldMinded\DexterCore\Contracts\CustomFieldInterface;
use Statamic\Fields\Field;

/**
 * Wraps a Statamic Blueprint field as a dexter-core CustomFieldInterface.
 *
 * Statamic handles are names, so unlike the ExpressionEngine build there is no
 * field-id-to-short-name mapping to reverse. getName() and getId() are both
 * the handle.
 */
class BlueprintField implements CustomFieldInterface
{
    public function __construct(
        private Field $field,
        private mixed $value = null
    ) {
    }

    public function getName(): string
    {
        return $this->field->handle();
    }

    /**
     * Core's contract allows int|string. Statamic has no numeric field ids,
     * so the handle serves as the identifier.
     */
    public function getId(): int|string
    {
        return $this->field->handle();
    }

    public function getType(): string
    {
        return $this->field->type();
    }

    public function getSettings(): array
    {
        return $this->field->config();
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getFacade(): object
    {
        return $this->field;
    }

    public function getDisplay(): string
    {
        return $this->field->display() ?? $this->field->handle();
    }
}
