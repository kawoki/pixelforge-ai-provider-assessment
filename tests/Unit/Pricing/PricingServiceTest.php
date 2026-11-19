<?php

declare(strict_types=1);

use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\RateCardData;
use App\Data\TokenUsageData;
use App\Enums\TokenType;
use App\Services\Pricing\PricingService;
use App\Services\Pricing\TokenEstimator;
use Database\Factories\GenerationFactory;
use Tests\Fakes\FakePixelforgeClient;
use Tests\Support\PixelforgeFixtures;

function pricing(): PricingService
{
    return new PricingService(new TokenEstimator, markup: '0.25');
}

function documentedRateCard(): RateCardData
{
    return RateCardData::fromArray(GenerationFactory::testRateCard());
}

it('matches Pixelforge\'s own numbers', function (array $usage, string $provider, string $user) {
    $cost = pricing()->actual(TokenUsageData::fromArray($usage), documentedRateCard());

    expect($cost->providerCost->toDecimalString())->toBe($provider)
        ->and($cost->userCost->toDecimalString())->toBe($user);
})->with([
    'docs: succeeded generation example' => [
        ['input_text' => 12, 'input_image' => 765, 'output' => 6450, 'reasoning' => 3184], '0.305510000000', '0.381887500000',
    ],
    'docs: /v1/usage day total (cost_usd 0.169080000)' => [
        ['input_text' => 41, 'input_image' => 0, 'output' => 3300, 'reasoning' => 2950], '0.169080000000', '0.211350000000',
    ],
    'a single text token is never rounded' => [['input_text' => 1], '0.000005000000', '0.000006250000'],
    'nothing used' => [[], '0.000000000000', '0.000000000000'],
]);

it('charges exactly 25% on top of the provider cost', function (array $usage) {
    $cost = pricing()->actual(TokenUsageData::fromArray($usage), documentedRateCard());

    expect($cost->userCost->minus($cost->providerCost)->equals($cost->providerCost->multipliedBy('0.25')))->toBeTrue();
})->with([
    [['input_text' => 7]],
    [['input_text' => 3, 'input_image' => 765, 'output' => 4300, 'reasoning' => 777]],
    [['reasoning' => 999_999]],
]);

it('itemises the cost per token type', function () {
    $cost = pricing()->actual(TokenUsageData::fromArray(['input_text' => 10, 'reasoning' => 1000]), documentedRateCard());

    expect($cost->lines)->toHaveCount(4)
        ->and($cost->lines[0]->type)->toBe(TokenType::InputText)
        ->and($cost->lines[0]->providerCost->toDecimalString())->toBe('0.000050000000')
        ->and($cost->lines[3]->providerCost->toDecimalString())->toBe('0.012500000000');
});

it('estimates the docs\' maximum-cost example exactly ($0.373875)', function () {
    $request = new GenerationRequestData(str_repeat('a', 400), '1536x1024', 'high', ['https://a.test/1.png', 'https://a.test/2.png']);
    $catalog = new PricingCatalogData(FakePixelforgeClient::RATES, PixelforgeFixtures::tokenRules());

    $estimate = pricing()->estimate($request, $catalog);

    expect($estimate->max->providerCost->toDecimalString())->toBe('0.373875000000')
        ->and($estimate->max->userCost->toDecimalString())->toBe('0.467343750000')
        ->and($estimate->min->providerCost->toDecimalString())->toBe('0.286225000000')
        ->and($estimate->min->userCost->toDecimalString())->toBe('0.357781250000');
});

it('snapshots Pixelforge rates with the configured markup', function () {
    $card = pricing()->rateCard(FakePixelforgeClient::RATES);

    expect($card->toArray())->toBe(GenerationFactory::testRateCard());
});
