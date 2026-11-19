<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;

/**
 * A priced usage: what Pixelforge charges us, and what we charge the user (ADR-0004).
 */
final readonly class CostBreakdownData
{
    /**
     * @param  list<CostLineData>  $lines
     */
    public function __construct(
        public TokenUsageData $usage,
        public array $lines,
        public Usd $providerCost,
        public Usd $userCost,
        public RateCardData $rateCard,
    ) {}
}
