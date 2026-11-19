<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\PricingCatalogData;
use App\Data\TokenRulesData;
use App\Enums\TokenType;

/**
 * Translates GET /v1/pricing (PIXELFORGE_API.md → Pricing) into PricingCatalogData.
 * Also parses the config fallback and cached copies, which use the same shape.
 */
final readonly class PricingPayloadMapper
{
    public function map(mixed $payload): PricingCatalogData
    {
        $pricing = PayloadReader::of($payload, 'pricing');

        return new PricingCatalogData($this->rates($pricing->child('rates')), $this->tokenRules($pricing->child('tokens')));
    }

    /**
     * @return array<string, string>
     */
    private function rates(PayloadReader $rates): array
    {
        $parsed = [];

        foreach (TokenType::cases() as $type) {
            $parsed[$type->value] = $rates->string($type->providerKey());
        }

        return $parsed;
    }

    private function tokenRules(PayloadReader $tokens): TokenRulesData
    {
        return new TokenRulesData(
            charactersPerTextToken: $tokens->int('characters_per_text_token', min: 1),
            textTokenVariance: $tokens->decimal('text_token_variance'),
            tokensPerReferenceImage: $tokens->int('per_reference_image'),
            outputTokens: $this->outputTable($tokens->child('output')),
            reasoningTokens: $this->reasoningTable($tokens->child('reasoning')),
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function outputTable(PayloadReader $output): array
    {
        $table = [];

        foreach ($output->keys() as $quality) {
            $sizes = $output->child($quality);
            $table[$quality] = array_combine($sizes->keys(), array_map($sizes->int(...), $sizes->keys()));
        }

        return $table;
    }

    /**
     * @return array<string, array{min: int, max: int}>
     */
    private function reasoningTable(PayloadReader $reasoning): array
    {
        $table = [];

        foreach ($reasoning->keys() as $quality) {
            $range = $reasoning->child($quality);
            $table[$quality] = ['min' => $range->int('min'), 'max' => $range->int('max')];
        }

        return $table;
    }
}
