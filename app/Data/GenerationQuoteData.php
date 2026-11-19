<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A price range plus whether this user can start the generation right now.
 */
final readonly class GenerationQuoteData
{
    public function __construct(
        public CostEstimateData $estimate,
        public BalanceSummaryData $balance,
        public ?string $limitReason = null,
    ) {}

    public function affordable(): bool
    {
        return $this->balance->canAfford($this->estimate->max->userCost);
    }
}
