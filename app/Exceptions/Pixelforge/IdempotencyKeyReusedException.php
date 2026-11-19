<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * The same Idempotency-Key was sent with different parameters (409 idempotency_key_reused). A bug on our side.
 */
final class IdempotencyKeyReusedException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
