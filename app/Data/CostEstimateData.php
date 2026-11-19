<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Up-front price range for a generation (ADR-0004). The final price is always within it,
 * as long as Pixelforge's published token rules hold.
 */
final readonly class CostEstimateData
{
    public function __construct(
        public CostBreakdownData $min,
        public CostBreakdownData $max,
    ) {}

    public function rateCard(): RateCardData
    {
        return $this->max->rateCard;
    }
}
