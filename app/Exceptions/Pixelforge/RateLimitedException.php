<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Over 60 requests/minute for the account (429 rate_limit_exceeded). Retry after Retry-After.
 */
final class RateLimitedException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
