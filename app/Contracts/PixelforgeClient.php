<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\ProviderGenerationData;
use App\Data\ProviderUsageData;
use App\Exceptions\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;

/**
 * The Pixelforge API as the rest of the app sees it (ADR-0002, ADR-0005).
 *
 * Implementations throw PixelforgeException subclasses; callers branch on
 * isRetryable(), never on HTTP details.
 */
interface PixelforgeClient
{
    /**
     * Submit a generation. $clientReference is our idempotency key: submitting the
     * same reference twice returns the existing generation and never bills twice.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(GenerationRequestData $request, string $clientReference): ProviderGenerationData;

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerGenerationId): ProviderGenerationData;

    /**
     * Current published rates and token-counting rules (GET /v1/pricing, no auth).
     *
     * @throws PixelforgeException
     */
    public function getPricing(): PricingCatalogData;

    /**
     * What Pixelforge billed this API key per UTC completion day (GET /v1/usage, max 92 days).
     *
     * @throws PixelforgeException
     */
    public function getUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsageData;
}
