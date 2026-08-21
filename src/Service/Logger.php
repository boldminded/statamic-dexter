<?php

namespace BoldMinded\Dexter\Service;

use BoldMinded\DexterCore\Contracts\LoggerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Routes dexter-core's logging to Laravel's logger under a dexter channel
 * prefix, so indexing output is greppable in a shared log.
 */
class Logger implements LoggerInterface
{
    public function emergency(string $message): void
    {
        Log::emergency($this->format($message));
    }

    public function alert(string $message): void
    {
        Log::alert($this->format($message));
    }

    public function critical(string $message): void
    {
        Log::critical($this->format($message));
    }

    public function error(string $message): void
    {
        Log::error($this->format($message));
    }

    public function warning(string $message): void
    {
        Log::warning($this->format($message));
    }

    public function notice(string $message): void
    {
        Log::notice($this->format($message));
    }

    public function info(string $message): void
    {
        Log::info($this->format($message));
    }

    public function debug(string $message): void
    {
        Log::debug($this->format($message));
    }

    private function format(string $message): string
    {
        return '[Dexter] ' . $message;
    }
}
