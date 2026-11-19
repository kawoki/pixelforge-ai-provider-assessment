<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Data\GenerationRequestData;
use App\Data\TokenRulesData;
use App\Data\TokenUsageData;
use App\Data\TokenUsageRangeData;
use App\Enums\TokenType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Predicts the token range of a generation before it runs (ADR-0004), using
 * Pixelforge's published counting rules (PIXELFORGE_API.md → "How tokens are counted"):
 *
 * - input text: ceil(chars / 4), actual within ±variance (15%) → known after completion
 * - input image: exactly 765 per reference image             → exact up front
 * - output:      exact per quality × size                      → exact up front
 * - reasoning:   min–max per quality                           → known after completion
 */
final readonly class TokenEstimator
{
    public function estimate(GenerationRequestData $request, TokenRulesData $rules): TokenUsageRangeData
    {
        $text = $this->textTokenRange($request->prompt, $rules);
        $reasoning = $rules->reasoningTokens($request->quality);

        return new TokenUsageRangeData(
            min: $this->usage($request, $rules, $text['min'], $reasoning['min']),
            max: $this->usage($request, $rules, $text['max'], $reasoning['max']),
        );
    }

    /**
     * ceil(chars / charsPerToken), widened by the published variance (floor below, ceil above).
     *
     * @return array{min: int, max: int}
     */
    private function textTokenRange(string $prompt, TokenRulesData $rules): array
    {
        $perToken = $rules->charactersPerTextToken;
        $estimate = intdiv(mb_strlen($prompt) + $perToken - 1, $perToken);

        return [
            'min' => self::scaled($estimate, BigDecimal::one()->minus($rules->textTokenVariance), RoundingMode::Floor),
            'max' => self::scaled($estimate, BigDecimal::one()->plus($rules->textTokenVariance), RoundingMode::Ceiling),
        ];
    }

    private function usage(GenerationRequestData $request, TokenRulesData $rules, int $textTokens, int $reasoningTokens): TokenUsageData
    {
        return TokenUsageData::fromArray([
            TokenType::InputText->value => $textTokens,
            TokenType::InputImage->value => count($request->referenceImageUrls) * $rules->tokensPerReferenceImage,
            TokenType::Output->value => $rules->outputTokens($request->quality, $request->size),
            TokenType::Reasoning->value => $reasoningTokens,
        ]);
    }

    private static function scaled(int $tokens, BigDecimal $factor, RoundingMode $rounding): int
    {
        return BigDecimal::of($tokens)->multipliedBy($factor)->toScale(0, $rounding)->toInt();
    }
}
