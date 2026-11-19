<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * Pixelforge's view of a generation, already translated into our vocabulary.
 * Produced from API responses and webhook `data.object` alike.
 */
final readonly class ProviderGenerationData
{
    /**
     * @param  array<string, string>|null  $rates  USD per 1M tokens, fixed by Pixelforge at creation
     */
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
        public ?string $clientReference = null,
        public ?TokenUsageData $usage = null,
        public ?array $rates = null,
        public ?string $imageUrl = null,
        public ?CarbonImmutable $imageExpiresAt = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $completedAt = null,
    ) {}
}
