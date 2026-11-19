<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

/**
 * Not found, or it belongs to another account (404 not_found) — Pixelforge does not say which.
 */
final class GenerationNotFoundException extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
