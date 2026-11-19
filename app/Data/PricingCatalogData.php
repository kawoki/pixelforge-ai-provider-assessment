<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TokenType;

/**
 * What Pixelforge currently publishes at GET /v1/pricing: rates and token-counting rules.
 */
final readonly class PricingCatalogData
{
    /**
     * @param  array<string, string>  $rates  USD per 1M tokens as decimal strings, keyed by TokenType value
     */
    public function __construct(
        public array $rates,
        public TokenRulesData $tokenRules,
    ) {}

    /**
     * Back to Pixelforge's published shape, so caches and config share one parser.
     *
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function toArray(): array
    {
        $rates = [];

        foreach (TokenType::cases() as $type) {
            $rates[$type->providerKey()] = $this->rates[$type->value];
        }

        return ['rates' => $rates, 'tokens' => $this->tokenRules->toArray()];
    }
}
