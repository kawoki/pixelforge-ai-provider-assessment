<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\PixelforgeClient;
use App\Data\ProviderUsageData;
use App\Exceptions\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge's own billing for a range (GET /v1/usage; cached by CachedPixelforgeClient).
 * Null when Pixelforge can't be reached: the report still renders.
 */
final readonly class ProviderUsageService
{
    public function __construct(
        private PixelforgeClient $pixelforge,
    ) {}

    public function forRange(CarbonImmutable $start, CarbonImmutable $end): ?ProviderUsageData
    {
        try {
            return $this->pixelforge->getUsage($start, $end);
        } catch (PixelforgeException $e) {
            Log::warning('Pixelforge usage unavailable for the spend report.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
