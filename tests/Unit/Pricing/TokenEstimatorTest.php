<?php

declare(strict_types=1);

use App\Data\GenerationRequestData;
use App\Data\TokenUsageRangeData;
use App\Enums\TokenType;
use App\Services\Pricing\TokenEstimator;
use Tests\Support\PixelforgeFixtures;

function estimateTokens(string $prompt, string $size = '1024x1024', string $quality = 'medium', array $refs = []): TokenUsageRangeData
{
    return (new TokenEstimator)->estimate(new GenerationRequestData($prompt, $size, $quality, $refs), PixelforgeFixtures::tokenRules());
}

it('widens the text estimate by the published ±15% variance', function (string $prompt, int $min, int $max) {
    $range = estimateTokens($prompt);

    expect($range->min->tokens(TokenType::InputText))->toBe($min)
        ->and($range->max->tokens(TokenType::InputText))->toBe($max);
})->with([
    'docs example: 400 chars → 100 → 85..115' => [str_repeat('a', 400), 85, 115],
    'rounds chars up, then floors/ceils the variance' => ['abcde', 1, 3],
    'empty prompt' => ['', 0, 0],
    'counts unicode characters, not bytes' => [str_repeat('🎨', 8), 1, 3],
]);

it('charges exactly 765 tokens per reference image', function () {
    $range = estimateTokens('x', refs: ['https://a.test/1.png', 'https://a.test/2.png']);

    expect($range->min->tokens(TokenType::InputImage))->toBe(1530)
        ->and($range->max->tokens(TokenType::InputImage))->toBe(1530);
});

it('uses the exact output table', function (string $size, string $quality, int $tokens) {
    $range = estimateTokens('x', $size, $quality);

    expect($range->min->tokens(TokenType::Output))->toBe($tokens)
        ->and($range->max->tokens(TokenType::Output))->toBe($tokens);
})->with([
    ['1024x1024', 'low', 280],
    ['1536x1024', 'medium', 1650],
    ['1024x1536', 'high', 6450],
]);

it('spans the published reasoning range for the quality', function (string $quality, int $min, int $max) {
    $range = estimateTokens('x', quality: $quality);

    expect($range->min->tokens(TokenType::Reasoning))->toBe($min)
        ->and($range->max->tokens(TokenType::Reasoning))->toBe($max);
})->with([
    ['low', 0, 500],
    ['medium', 200, 2000],
    ['high', 1000, 8000],
]);

it('rejects sizes and qualities Pixelforge does not publish', function (string $size, string $quality) {
    estimateTokens('x', $size, $quality);
})->throws(InvalidArgumentException::class)->with([
    ['512x512', 'low'],
    ['1024x1024', 'ultra'],
]);
