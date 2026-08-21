<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\QueueInterface;

/**
 * Bridges dexter-core's queue contract to Laravel's dispatcher.
 *
 * Core pushes a job class name plus a data array. Laravel jobs take
 * constructor arguments, so the named job is expected to accept a single
 * array -- see the Jobs namespace.
 */
class Queue implements QueueInterface
{
    public function push(string $job, array $data): void
    {
        if (! class_exists($job)) {
            return;
        }

        dispatch(new $job($data));
    }
}
