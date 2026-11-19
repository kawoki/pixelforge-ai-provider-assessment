<?php

declare(strict_types=1);

namespace App\Exceptions\Pixelforge;

use LogicException;

/**
 * PIXELFORGE_BASE_URL or PIXELFORGE_API_KEY is not set. A deployment error, not a provider failure.
 */
final class PixelforgeNotConfiguredException extends LogicException
{
    public static function missing(string $setting): self
    {
        return new self("Pixelforge is not configured: [{$setting}] is empty. Check your environment.");
    }
}
