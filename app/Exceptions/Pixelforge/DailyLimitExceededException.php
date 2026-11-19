<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * The account's 500 generations per UTC day are used up (429 daily_limit_exceeded). Retrying before the next UTC day only burns the per-minute limit.
 */
final class DailyLimitExceededException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
