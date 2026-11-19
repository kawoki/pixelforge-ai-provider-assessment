<?php

declare(strict_types=1);

namespace App\Data;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * How Pixelforge counts tokens (GET /v1/pricing → tokens). Used only for estimates;
 * actual cost always uses the usage Pixelforge reports.
 */
final readonly class TokenRulesData
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality → size → exact output tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality → range
     */
    public function __construct(
        public int $charactersPerTextToken,
        public BigDecimal $textTokenVariance,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    public function outputTokens(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("No output token count for [{$quality}, {$size}].");
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningTokens(string $quality): array
    {
        return $this->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("No reasoning token range for quality [{$quality}].");
    }

    /**
     * Pixelforge's published `tokens` shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance' => (string) $this->textTokenVariance,
            'per_reference_image' => $this->tokensPerReferenceImage,
            'output' => $this->outputTokens,
            'reasoning' => $this->reasoningTokens,
        ];
    }
}
