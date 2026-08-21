<?php

namespace BoldMinded\Dexter\Service\Fields;

use BoldMinded\Dexter\Service\Fields\Type\Assets;
use BoldMinded\Dexter\Service\Fields\Type\Bard;
use BoldMinded\Dexter\Service\Fields\Type\Grid;
use BoldMinded\Dexter\Service\Fields\Type\Group;
use BoldMinded\Dexter\Service\Fields\Type\Markdown;
use BoldMinded\Dexter\Service\Fields\Type\Relationship;
use BoldMinded\Dexter\Service\Fields\Type\Replicator;

/**
 * Resolves a Statamic fieldtype to the class that extracts its values.
 *
 * The Statamic counterpart to EE's FieldTypeHandlerFactory and Craft's
 * ElementTypeFactory. Handlers are registered by the type strings they declare,
 * so a class claiming several fieldtypes -- Relationship covers five -- names
 * them in one place rather than being listed repeatedly here.
 *
 * Only fieldtypes needing special handling have one. Anything unregistered
 * falls back to the walker's scalar extraction, which is correct for the great
 * majority of Statamic fieldtypes.
 */
class FieldTypeFactory
{
    /**
     * @var array<string, FieldTypeInterface>
     */
    private array $handlers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        foreach ([
            new Bard(),
            new Replicator(),
            new Grid(),
            new Group(),
            new Assets(),
            new Markdown(),
            new Relationship(),
        ] as $handler) {
            $this->register($handler);
        }
    }

    private function register(FieldTypeInterface $handler): void
    {
        foreach ($handler->getTypes() as $type) {
            $this->handlers[$type] = $handler;
        }
    }

    /**
     * The handler for a fieldtype, or null when it has none and should fall
     * back to scalar extraction.
     */
    public function make(string $fieldType): ?FieldTypeInterface
    {
        return $this->handlers[$fieldType] ?? null;
    }

    public function isSupported(string $fieldType): bool
    {
        return isset($this->handlers[$fieldType]);
    }
}
