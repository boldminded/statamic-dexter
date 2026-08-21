<?php

namespace BoldMinded\Dexter\Service\Indexer;

use BoldMinded\DexterCore\Service\Indexer\DeleteCommand;

/**
 * Overrides a core delete command's unique id.
 *
 * Core's delete commands rebuild their unique id from an integer site id --
 * `entry_{siteId}_{id}` -- a shape inherited from ExpressionEngine. Statamic
 * sites are string handles, so an entry indexed as `entry_default_home` would
 * be deleted as `entry_0_home` and the delete would silently miss, leaving
 * the document orphaned in the index.
 *
 * Wrapping the command lets the Indexable's own unique id win, so index and
 * delete always agree, without changing core's contract for the EE and Craft
 * builds.
 */
class DeleteAdapter implements DeleteCommand
{
    public function __construct(
        private DeleteCommand $command,
        private string $uniqueId,
    ) {
    }

    public function execute(): bool
    {
        return $this->command->execute();
    }

    public function getIndexName(): string
    {
        return $this->command->getIndexName();
    }

    public function getId(): int|string
    {
        return $this->command->getId();
    }

    public function getSiteId(): int
    {
        return $this->command->getSiteId();
    }

    public function getTitle(): string
    {
        return $this->command->getTitle();
    }

    public function getUniqueId(): string
    {
        return $this->uniqueId;
    }

    public function getQueueJobName(): string
    {
        return $this->command->getQueueJobName();
    }
}
