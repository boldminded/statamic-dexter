<?php

namespace BoldMinded\Dexter\Jobs;

use BoldMinded\DexterCore\Contracts\IndexableInterface;
use BoldMinded\Dexter\Service\Indexer\Indexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Base for the queued index jobs.
 *
 * Only a reference is serialised, never the content object. Statamic content
 * is not Eloquent, so it has no SerializesModels support, and a payload
 * carrying a whole entry would go stale between dispatch and execution.
 */
abstract class AbstractIndexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public array $payload)
    {
    }

    /**
     * Re-fetch the content this job refers to, or null when it has since
     * been deleted.
     */
    abstract protected function resolve(): ?IndexableInterface;

    public function handle(Indexer $indexer): void
    {
        $indexable = $this->resolve();

        if ($indexable === null) {
            // Content deleted between dispatch and execution. The delete
            // listener will have removed it from the index already.
            return;
        }

        // false: this job *is* the queued work, so run inline rather than
        // pushing another job and looping.
        $indexer->index($indexable, false);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error(sprintf(
            '[Dexter] %s failed for %s: %s',
            static::class,
            $this->reference(),
            $exception->getMessage()
        ));
    }

    protected function reference(): string
    {
        return (string) ($this->payload['id'] ?? 'unknown');
    }
}
