<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Pixelforge rejected the request itself: validation_failed (422), content_policy_violation (400),
 * date_range_too_long (422). Nothing was created or billed; retrying unchanged will not help.
 */
final class InvalidRequestException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
