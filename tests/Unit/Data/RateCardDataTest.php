<?php

declare(strict_types=1);

use App\Data\RateCardData;
use App\Enums\TokenType;
use App\Exceptions\Pricing\InvalidRateCardException;
use Database\Factories\GenerationFactory;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rateCardInput(array $overrides = []): array
{
    return array_replace_recursive(GenerationFactory::testRateCard(), $overrides);
}

it('parses a valid rate card', function () {
    $card = RateCardData::fromArray(rateCardInput());

    expect((string) $card->ratePerMillion(TokenType::Reasoning))->toBe('12.50')
        ->and((string) $card->multiplier())->toBe('1.25');
});

it('prices tokens per million exactly', function (TokenType $type, int $tokens, string $cost) {
    expect(RateCardData::fromArray(rateCardInput())->costOf($type, $tokens)->toDecimalString())->toBe($cost);
})->with([
    'one token at $5/1M' => [TokenType::InputText, 1, '0.000005000000'],
    'one token at $12.50/1M' => [TokenType::Reasoning, 1, '0.000012500000'],
    'a million at $40/1M' => [TokenType::Output, 1_000_000, '40.000000000000'],
]);

it('round-trips through its snapshot form', function () {
    $snapshot = RateCardData::fromArray(rateCardInput())->toArray();

    expect(RateCardData::fromArray($snapshot)->toArray())->toBe($snapshot);
});

it('rejects malformed rate cards', function (array $input) {
    RateCardData::fromArray($input);
})->throws(InvalidRateCardException::class)->with([
    'float rate' => [rateCardInput(['rates' => ['input_text' => 5.0]])],
    'float markup' => [rateCardInput(['markup' => 0.25])],
    'negative rate' => [rateCardInput(['rates' => ['input_text' => '-0.1']])],
    'not a number' => [rateCardInput(['rates' => ['input_text' => 'abc']])],
    'unknown token type' => [rateCardInput(['rates' => ['video' => '0.1']])],
    'missing rate' => [['markup' => '0.25', 'rates' => ['input_text' => '5.00']]],
]);

it('rejects rates too precise to price exactly with the markup', function () {
    // 5 rate decimals + 6 (per-1M) + 2 multiplier decimals (1.25) = 13 > 12.
    RateCardData::fromArray(rateCardInput(['rates' => ['input_text' => '5.00001']]));
})->throws(InvalidRateCardException::class, 'need 13 decimal places');

it('accepts the most precise rate that still prices exactly', function () {
    $card = RateCardData::fromArray(rateCardInput(['rates' => ['input_text' => '5.0001']]));

    expect($card->costOf(TokenType::InputText, 1)->multipliedBy($card->multiplier())->toDecimalString())
        ->toBe('0.000006250125');
});
