<?php

declare(strict_types=1);

use App\Data\TokenUsageData;
use App\Enums\TokenType;

it('fills missing token types with zero', function () {
    $usage = TokenUsageData::fromArray(['output' => 1100]);

    expect($usage->tokens(TokenType::Output))->toBe(1100)
        ->and($usage->tokens(TokenType::Reasoning))->toBe(0)
        ->and($usage->toArray())->toBe(['input_text' => 0, 'input_image' => 0, 'output' => 1100, 'reasoning' => 0]);
});

it('rejects invalid counts', function (array $counts) {
    TokenUsageData::fromArray($counts);
})->throws(InvalidArgumentException::class)->with([
    'negative' => [['input_text' => -1]],
    'float' => [['input_text' => 1.5]],
    'numeric string' => [['input_text' => '3']],
    'unknown type' => [['video' => 3]],
]);

it('knows Pixelforge\'s key for each token type', function () {
    expect(TokenType::InputText->providerKey())->toBe('input_text_tokens')
        ->and(TokenType::Reasoning->providerKey())->toBe('reasoning_tokens');
});
