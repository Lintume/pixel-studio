<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * Any non-2xx answer from Pixelforge, or no answer at all.
 *
 * The one question callers ask is "may I try again?". Rate limits, overloads,
 * 5xx and network failures: yes, after retryAfter seconds. A blocked prompt, a
 * validation error, the daily quota or a bad key: no, the same request will be
 * refused the same way.
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly ?int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function network(string $message): self
    {
        return new self(null, 'network_error', $message);
    }

    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true; // timeout or connection error: the request may or may not have landed
        }

        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || $this->status >= 500;
    }
}
