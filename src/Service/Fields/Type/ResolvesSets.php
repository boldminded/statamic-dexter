<?php

namespace BoldMinded\Dexter\Service\Fields\Type;

use BoldMinded\Dexter\Service\Fields\FieldValue;
use BoldMinded\Dexter\Service\Fields\FieldValueWalker;
use Statamic\Fields\Field;

/**
 * Set resolution shared by Replicator and Bard.
 *
 * Both store a list of sets, each naming its own type, and both declare those
 * types the same way in the blueprint -- so the lookup and its fallback belong
 * in one place rather than in each handler.
 */
trait ResolvesSets
{
    /**
     * Resolve one set's field definitions and extract its values.
     *
     * @return array<string, mixed>
     */
    protected function setFields(
        Field $field,
        string $setType,
        array $values,
        FieldValueWalker $walker
    ): array {
        $definition = $this->findSet($field->config()['sets'] ?? [], $setType);

        if ($definition === null) {
            // Set type not in the blueprint (renamed or removed). Fall back to
            // indexing its scalar values so content is not silently lost.
            return FieldValue::scalarsOnly($values);
        }

        return $walker->walk(
            $walker->fieldsFrom($definition['fields'] ?? []),
            $values
        );
    }

    /**
     * Sets may be declared flat, or nested one level under a display group.
     */
    protected function findSet(array $sets, string $setType): ?array
    {
        if (isset($sets[$setType]) && is_array($sets[$setType])) {
            return $sets[$setType];
        }

        foreach ($sets as $group) {
            if (isset($group['sets'][$setType]) && is_array($group['sets'][$setType])) {
                return $group['sets'][$setType];
            }
        }

        return null;
    }
}
