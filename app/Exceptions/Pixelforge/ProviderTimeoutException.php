<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * We could not reach Pixelforge or it did not answer in time. The request may have been accepted;
 * retrying is safe because creates carry an Idempotency-Key (a replay returns the existing generation).
 */
final class ProviderTimeoutException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
