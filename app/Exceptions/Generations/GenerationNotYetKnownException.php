<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use RuntimeException;

/**
 * Pixelforge told us about a generation we cannot find (yet). Usually a webhook that
 * arrived before our own record was committed; retrying a little later resolves it.
 */
final class GenerationNotYetKnownException extends RuntimeException
{
    public static function for(string $providerGenerationId): self
    {
        return new self("No local generation for Pixelforge generation [{$providerGenerationId}] yet.");
    }
}
