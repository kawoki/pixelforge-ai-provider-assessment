<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Data\CostBreakdownData;
use App\Data\CostEstimateData;
use App\Data\CostLineData;
use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\RateCardData;
use App\Data\TokenUsageData;
use App\Enums\TokenType;
use App\Support\Money\Usd;
use Illuminate\Container\Attributes\Config;

/**
 * The only place prices are calculated (ADR-0004).
 *
 * provider cost = Σ usage.<type> × rates.<type> / 1,000,000   (what Pixelforge bills us)
 * user cost     = provider cost × (1 + markup)
 *
 * All math is exact; RateCardData guarantees the result fits Usd::SCALE.
 */
final readonly class PricingService
{
    public function __construct(
        private TokenEstimator $estimator,
        #[Config('pixelforge.markup')] private string $markup,
    ) {}

    /**
     * Up-front min–max price, from Pixelforge's current published rates and token rules.
     */
    public function estimate(GenerationRequestData $request, PricingCatalogData $catalog): CostEstimateData
    {
        $rateCard = $this->rateCard($catalog->rates);
        $tokens = $this->estimator->estimate($request, $catalog->tokenRules);

        return new CostEstimateData($this->price($tokens->min, $rateCard), $this->price($tokens->max, $rateCard));
    }

    /**
     * Final cost from the usage Pixelforge reported, at the generation's snapshotted rates.
     */
    public function actual(TokenUsageData $usage, RateCardData $rateCard): CostBreakdownData
    {
        return $this->price($usage, $rateCard);
    }

    /**
     * Pixelforge rates combined with our current markup, ready to snapshot on a generation.
     *
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by TokenType value
     */
    public function rateCard(array $rates): RateCardData
    {
        return RateCardData::fromArray(['rates' => $rates, 'markup' => $this->markup]);
    }

    private function price(TokenUsageData $usage, RateCardData $rateCard): CostBreakdownData
    {
        $lines = array_map(
            fn (TokenType $type): CostLineData => $this->line($type, $usage, $rateCard),
            TokenType::cases(),
        );
        $providerCost = array_reduce(
            $lines,
            fn (Usd $sum, CostLineData $line): Usd => $sum->plus($line->providerCost),
            Usd::zero(),
        );

        return new CostBreakdownData($usage, $lines, $providerCost, $providerCost->multipliedBy($rateCard->multiplier()), $rateCard);
    }

    private function line(TokenType $type, TokenUsageData $usage, RateCardData $rateCard): CostLineData
    {
        $tokens = $usage->tokens($type);

        return new CostLineData($type, $tokens, $rateCard->ratePerMillion($type), $rateCard->costOf($type, $tokens));
    }
}
