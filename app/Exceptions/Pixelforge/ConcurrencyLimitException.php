<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * 3 generations already queued/processing for the account (429 concurrency_limit_exceeded). Retry once one finishes.
 */
final class ConcurrencyLimitException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
