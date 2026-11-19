<?php

declare(strict_types=1);

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Enums\GenerationStatus;
use App\Enums\TokenType;
use App\Exceptions\Pixelforge\ConcurrencyLimitException;
use App\Exceptions\Pixelforge\DailyLimitExceededException;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\IdempotencyKeyReusedException;
use App\Exceptions\Pixelforge\InvalidRequestException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\PixelforgeNotConfiguredException;
use App\Exceptions\Pixelforge\ProviderAuthException;
use App\Exceptions\Pixelforge\ProviderTimeoutException;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Exceptions\Pixelforge\RateLimitedException;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Services\Pixelforge\CachedPixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pixelforge.test/v1', 'pixelforge.api_key' => 'pf_test_key']);
});

function client(): PixelforgeClient
{
    return app(PixelforgeClient::class);
}

function aRequest(array $refs = ['https://refs.test/fox.png']): GenerationRequestData
{
    return new GenerationRequestData('a red fox', '1536x1024', 'high', $refs);
}

/**
 * @param  array<string, mixed>  $error
 */
function errorBody(string $code, string $message = 'Nope.'): array
{
    return ['error' => ['type' => 'some_error', 'code' => $code, 'message' => $message]];
}

it('is bound behind the caching decorator', function () {
    expect(client())->toBeInstanceOf(CachedPixelforgeClient::class);
});

it('creates a generation with auth, idempotency key and only documented params', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixtures::generationPayload(['status' => 'queued', 'usage' => null]), 202)]);

    $generation = client()->createGeneration(aRequest(), 'ref-123');

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->rates)->toBe(['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'ref-123')
        && $request->data() === [
            'prompt' => 'a red fox',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://refs.test/fox.png'],
            'metadata' => ['client_reference' => 'ref-123'],
        ]);
});

it('omits reference_images when there are none', function () {
    Http::fake(['*' => Http::response(PixelforgeFixtures::generationPayload(['status' => 'queued', 'usage' => null]), 202)]);

    client()->createGeneration(aRequest(refs: []), 'ref');

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('reference_images', $request->data()));
});

it('retrieves a generation, url-encoding the id', function () {
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixtures::generationPayload())]);

    $generation = client()->getGeneration('gen/1');

    expect($generation->usage?->tokens(TokenType::Reasoning))->toBe(3184);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/generations/gen%2F1');
});

it('reads pricing without sending the API key', function () {
    config(['pixelforge.api_key' => null]);
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(PixelforgeFixtures::pricingPayload())]);

    expect(client()->getPricing()->tokenRules->tokensPerReferenceImage)->toBe(765);
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('maps every documented error code to a typed, retry-aware exception', function (int $status, string $code, array $headers, string $class, bool $retryable, ?int $retryAfter) {
    Http::fake(['*' => Http::response(errorBody($code), $status, $headers)]);

    try {
        client()->createGeneration(aRequest(), 'ref');
        $this->fail('Expected an exception.');
    } catch (PixelforgeException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->isRetryable())->toBe($retryable)
            ->and($e->retryAfterSeconds())->toBe($retryAfter)
            ->and($e->providerCode())->toBe($code);
    }
})->with([
    [401, 'invalid_api_key', [], ProviderAuthException::class, false, null],
    [422, 'validation_failed', [], InvalidRequestException::class, false, null],
    [400, 'content_policy_violation', [], InvalidRequestException::class, false, null],
    [404, 'not_found', [], GenerationNotFoundException::class, false, null],
    [409, 'idempotency_key_reused', [], IdempotencyKeyReusedException::class, false, null],
    [429, 'rate_limit_exceeded', ['Retry-After' => '12'], RateLimitedException::class, true, 12],
    [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], ConcurrencyLimitException::class, true, 5],
    [429, 'daily_limit_exceeded', [], DailyLimitExceededException::class, false, null],
    [503, 'overloaded', ['Retry-After' => '2'], ProviderUnavailableException::class, true, 2],
]);

it('falls back to the HTTP status for codes it does not know', function (int $status, string $class) {
    Http::fake(['*' => Http::response(errorBody('brand_new_code'), $status)]);

    expect(fn () => client()->getGeneration('gen_1'))->toThrow($class);
})->with([
    [429, RateLimitedException::class],
    [500, ProviderUnavailableException::class],
    [418, UnexpectedProviderResponseException::class],
]);

it('keeps the provider message for users', function () {
    Http::fake(['*' => Http::response(errorBody('content_policy_violation', 'Prompt rejected by content policy.'), 400)]);

    expect(fn () => client()->createGeneration(aRequest(), 'ref'))
        ->toThrow(InvalidRequestException::class, 'Prompt rejected by content policy.');
});

it('understands Retry-After given as an HTTP date', function () {
    $this->freezeTime();
    Http::fake(['*' => Http::response(errorBody('rate_limit_exceeded'), 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()])]);

    expect(fn () => client()->createGeneration(aRequest(), 'ref'))
        ->toThrow(fn (RateLimitedException $e) => expect($e->retryAfterSeconds())->toBe(90));
});

it('treats connection failures as retryable timeouts', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 28: timed out')]);

    expect(fn () => client()->createGeneration(aRequest(), 'ref'))
        ->toThrow(fn (ProviderTimeoutException $e) => expect($e->isRetryable())->toBeTrue());
});

it('rejects responses it cannot interpret', function (mixed $body) {
    Http::fake(['*' => Http::response($body, 200)]);

    client()->getGeneration('gen_1');
})->throws(UnexpectedProviderResponseException::class)->with([
    'missing id' => [['status' => 'queued']],
    'unknown status' => [PixelforgeFixtures::generationPayload(['status' => 'cancelled'])],
    'not an object' => ['"ok"'],
]);

it('fails loudly when not configured', function (string $key, string $missing) {
    config([$key => null]);
    Http::fake();

    expect(fn () => client()->getGeneration('gen_1'))->toThrow(PixelforgeNotConfiguredException::class, $missing);
    Http::assertNothingSent();
})->with([
    ['pixelforge.base_url', 'PIXELFORGE_BASE_URL'],
    ['pixelforge.api_key', 'PIXELFORGE_API_KEY'],
]);

it('asks for billed usage with the documented date parameters and our key', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
        ['date' => '2026-11-19', 'generations' => 3, 'cost_usd' => '0.167082500'],
    ]])]);

    $usage = client()->getUsage(CarbonImmutable::parse('2026-11-19'), CarbonImmutable::parse('2026-11-19'));

    expect($usage->forDate('2026-11-19')?->cost->toDecimalString())->toBe('0.167082500000');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-19&end_date=2026-11-19'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});
