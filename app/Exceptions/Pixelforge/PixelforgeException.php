<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * Base for every failure talking to Pixelforge (ADR-0005).
 *
 * Callers decide what to do from isRetryable() and retryAfterSeconds(),
 * never from HTTP status codes.
 */
abstract class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $retryAfterSeconds = null,
        private readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    abstract public function isRetryable(): bool;

    /**
     * Seconds the provider asked us to wait (Retry-After), if it said.
     */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Pixelforge's machine-readable error code, e.g. "rate_limit_exceeded".
     */
    public function providerCode(): ?string
    {
        return $this->providerCode;
    }
}
