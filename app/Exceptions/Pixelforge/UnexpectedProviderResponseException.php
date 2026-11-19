<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Pixelforge answered with something we cannot interpret (unknown status, missing fields, other 4xx).
 */
final class UnexpectedProviderResponseException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
