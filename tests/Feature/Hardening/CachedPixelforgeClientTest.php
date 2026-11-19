<?php

declare(strict_types=1);

use App\Contracts\PixelforgeClient;
use App\Enums\AlertType;
use App\Mail\AdminAlertMail;
use App\Models\User;
use App\Services\Pixelforge\ProviderUsageCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_key']);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
});

/**
 * @param  array<string, mixed>|null  $pricing
 */
function fakePixelforgeHttp(?array $pricing = null): void
{
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response($pricing ?? PixelforgeFixtures::pricingPayload()),
        'pixelforge.test/v1/usage*' => Http::response(['data' => [['date' => '2026-11-19', 'generations' => 0, 'cost_usd' => '0.000000000']]]),
        'pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixtures::generationPayload()),
    ]);
}

function pixelforge(): PixelforgeClient
{
    return app(PixelforgeClient::class);
}

function requestsTo(string $path): int
{
    return Http::recorded(fn ($request) => str_contains($request->url(), $path))->count();
}

it('asks for pricing once an hour, not on every estimate', function () {
    fakePixelforgeHttp();
    pixelforge()->getPricing();
    pixelforge()->getPricing();
    expect(requestsTo('/v1/pricing'))->toBe(1);

    $this->travel(61)->minutes();
    pixelforge()->getPricing();
    expect(requestsTo('/v1/pricing'))->toBe(2);
});

it('keeps finished days of usage for an hour, but refreshes today every few minutes', function () {
    fakePixelforgeHttp();
    $yesterday = CarbonImmutable::parse('2026-11-19');
    $today = CarbonImmutable::parse('2026-11-20');

    pixelforge()->getUsage($yesterday, $yesterday);
    pixelforge()->getUsage($today, $today);
    $this->travel(6)->minutes();
    pixelforge()->getUsage($yesterday, $yesterday);
    pixelforge()->getUsage($today, $today);

    expect(requestsTo('start_date=2026-11-19'))->toBe(1)->and(requestsTo('start_date=2026-11-20'))->toBe(2);
});

it('fetches Pixelforge\'s usage again as soon as one of our generations succeeds', function () {
    fakePixelforgeHttp();
    $today = CarbonImmutable::parse('2026-11-20');

    pixelforge()->getUsage($today, $today);
    pixelforge()->getUsage($today, $today);
    app(ProviderUsageCache::class)->invalidate();
    pixelforge()->getUsage($today, $today);

    expect(requestsTo('/v1/usage'))->toBe(2);
});

it('tells admins once an hour when Pixelforge\'s pricing no longer matches our fallback copy', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $changed = PixelforgeFixtures::pricingPayload();
    $changed['rates']['output_tokens'] = '45.00';
    fakePixelforgeHttp($changed);

    pixelforge()->getPricing();
    Cache::forget('pixelforge-pricing'); // a refresh within the same hour
    pixelforge()->getPricing();

    Mail::assertSent(AdminAlertMail::class, 1);
    Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === AlertType::PricingChanged);
});

it('passes generation calls straight through (they change until finished)', function () {
    fakePixelforgeHttp();

    pixelforge()->getGeneration('gen_1');
    pixelforge()->getGeneration('gen_1');

    expect(requestsTo('/v1/generations/'))->toBe(2);
});
