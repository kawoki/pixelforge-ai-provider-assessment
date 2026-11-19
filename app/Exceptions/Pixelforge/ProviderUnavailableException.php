<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Pixelforge is overloaded (503 overloaded) or erroring (5xx). Nothing was created; retry with the same Idempotency-Key.
 */
final class ProviderUnavailableException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
