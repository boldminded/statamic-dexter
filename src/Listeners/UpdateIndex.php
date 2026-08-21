<?php

namespace BoldMinded\Dexter\Listeners;

use BoldMinded\Dexter\Service\IndexableFactory;
use BoldMinded\Dexter\Service\Indexer\Indexer;
use BoldMinded\Dexter\Jobs\IndexAssetJob;
use BoldMinded\Dexter\Jobs\IndexEntryJob;
use BoldMinded\Dexter\Jobs\IndexTermJob;
use BoldMinded\Dexter\Jobs\IndexUserJob;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetReplaced;
use Statamic\Events\AssetReuploaded;
use Statamic\Events\AssetSaved;
use Statamic\Events\AssetUploaded;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserSaved;

/**
 * Keeps the search index in step with content changes.
 *
 * Subscribes rather than registering one listener per event so the save and
 * delete paths stay side by side and the event list is readable in one place.
 */
class UpdateIndex
{
    public function __construct(
        private Indexer $indexer,
        private IndexableFactory $factory,
    ) {
    }

    public function subscribe($events): array
    {
        return [
            EntrySaved::class => 'save',
            EntryScheduleReached::class => 'save',
            EntryDeleted::class => 'delete',

            TermSaved::class => 'save',
            TermDeleted::class => 'delete',

            AssetSaved::class => 'save',
            AssetUploaded::class => 'save',
            AssetReplaced::class => 'save',
            AssetReuploaded::class => 'save',
            AssetDeleted::class => 'delete',

            UserSaved::class => 'save',
            UserDeleted::class => 'delete',
        ];
    }

    public function save($event): void
    {
        if (! config('dexter.enabled', true)) {
            return;
        }

        // Every localization is indexed, deliberately.
        //
        // Statamic fires one save event per localization, and an earlier
        // version gated on EntrySaved::isInitial() believing that prevented
        // duplicates. It does not: isInitial() reports which localization
        // *started* the cascade, so the gate skipped every other one and a
        // French page was never indexed at all.
        //
        // There is no duplicate to prevent. Each localization is its own
        // document with its own unique id -- entry_default_menu against
        // entry_fr_2a77aa4b... -- so indexing each save writes to a distinct
        // record.
        $item = $this->itemFrom($event);

        if ($item === null) {
            return;
        }

        $indexable = $this->factory->make($item);

        if ($indexable === null) {
            return;
        }

        // Nothing to do when this content's source is not configured.
        if ($this->indexer->indexNameFor($indexable) === null) {
            return;
        }

        if ($this->shouldQueue()) {
            $this->dispatchJob($indexable->getScope(), $indexable->getId());

            return;
        }

        $this->indexer->index($indexable, false);
    }

    public function delete($event): void
    {
        if (! config('dexter.enabled', true)) {
            return;
        }

        // Every localization is indexed, deliberately.
        //
        // Statamic fires one save event per localization, and an earlier
        // version gated on EntrySaved::isInitial() believing that prevented
        // duplicates. It does not: isInitial() reports which localization
        // *started* the cascade, so the gate skipped every other one and a
        // French page was never indexed at all.
        //
        // There is no duplicate to prevent. Each localization is its own
        // document with its own unique id -- entry_default_menu against
        // entry_fr_2a77aa4b... -- so indexing each save writes to a distinct
        // record.
        $item = $this->itemFrom($event);

        if ($item === null) {
            return;
        }

        $indexable = $this->factory->make($item);

        if ($indexable === null) {
            return;
        }

        // Deletes run inline. Queueing them would race the content being gone
        // from disk, and a delete is a single cheap call.
        $this->indexer->delete($indexable, false);
    }

    private function itemFrom($event): ?object
    {
        return $event->entry
            ?? $event->term
            ?? $event->asset
            ?? $event->user
            ?? null;
    }

    private function shouldQueue(): bool
    {
        return (bool) config('dexter.queue', false);
    }

    private function dispatchJob(string $scope, int|string $id): void
    {
        $job = match ($scope) {
            'entries' => IndexEntryJob::class,
            'categories' => IndexTermJob::class,
            'files' => IndexAssetJob::class,
            'users' => IndexUserJob::class,
            default => null,
        };

        if ($job !== null) {
            dispatch(new $job(['id' => $id]));
        }
    }
}
