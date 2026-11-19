<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\ConcurrencyLimitException;
use App\Exceptions\Pixelforge\ProviderTimeoutException;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Exceptions\Pixelforge\RateLimitedException;
use App\Models\Generation;
use App\Services\Generations\SubmissionRetryPolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function retryPolicy(): SubmissionRetryPolicy
{
    return new SubmissionRetryPolicy(new Repository(new ArrayStore), [2, 5, 15, 30, 60], maxAttemptsWhenUnavailable: 5);
}

function expectDelayBetween(int $delay, int $base): void
{
    expect($delay)->toBeGreaterThanOrEqual($base)->toBeLessThanOrEqual($base + intdiv($base, 4));
}

it('waits exactly as long as Pixelforge asks while waiting for a free slot', function () {
    expectDelayBetween(retryPolicy()->delaySeconds(new ConcurrencyLimitException('3 running', 5), attempt: 9), 5);
});

it('backs off further each time on overloads, never shorter than Retry-After', function (int $attempt, int $base) {
    expectDelayBetween(retryPolicy()->delaySeconds(new ProviderUnavailableException('overloaded', 2, 'overloaded'), $attempt), $base);
})->with([
    'Retry-After (2 s) vs first backoff (2 s)' => [1, 2],
    'second backoff beats Retry-After' => [2, 5],
    'third' => [3, 15],
    'stays at the longest' => [12, 60],
]);

it('gives up after a few refusals that created nothing', function (Exception $refusal) {
    $policy = retryPolicy();
    $generation = new Generation;
    $generation->id = 'gen-1';

    $verdicts = array_map(fn () => $policy->exhausted($generation, $refusal), range(1, 5));

    expect($verdicts)->toBe([false, false, false, false, true]);
})->with([
    'overloaded' => [new ProviderUnavailableException('overloaded', 2, 'overloaded')],
    'rate limited' => [new RateLimitedException('slow down', 10, 'rate_limit_exceeded')],
]);

it('never gives up early while waiting for a slot, or when Pixelforge might have accepted it', function (Exception $error) {
    $policy = retryPolicy();
    $generation = new Generation;
    $generation->id = 'gen-2';

    $verdicts = array_map(fn () => $policy->exhausted($generation, $error), range(1, 20));

    expect(array_unique($verdicts))->toBe([false]);
})->with([
    'concurrency limit' => [new ConcurrencyLimitException('3 running', 5)],
    'timeout (ambiguous)' => [new ProviderTimeoutException('timed out')],
]);
