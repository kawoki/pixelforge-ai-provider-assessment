<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Our API key is missing, malformed or revoked (401 invalid_api_key). An operator must fix configuration.
 */
final class ProviderAuthException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
