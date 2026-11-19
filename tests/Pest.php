<?php

use App\Contracts\PixelforgeClient;
use App\Enums\BackgroundProcess;
use App\Services\Health\BackgroundHealthService;
use App\Services\Health\HeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePixelforgeClient;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Swap the real Pixelforge API for an in-memory fake (ADR-0014).
 */
function fakePixelforge(): FakePixelforgeClient
{
    $fake = new FakePixelforgeClient;
    app()->instance(PixelforgeClient::class, $fake);

    return $fake;
}

/**
 * Pretend the scheduler and queue worker beat just now (ADR-0015).
 */
function backgroundIsUp(): void
{
    $heartbeats = app(HeartbeatService::class);
    $heartbeats->beat(BackgroundProcess::Scheduler);
    $heartbeats->beat(BackgroundProcess::QueueWorker);
    app(BackgroundHealthService::class)->forget();
}

/**
 * Pretend the given processes stopped beating 10 minutes ago, well after start-up.
 */
function backgroundIsDown(BackgroundProcess ...$processes): void
{
    $processes = $processes === [] ? BackgroundProcess::cases() : $processes;
    backgroundIsUp();

    foreach ($processes as $process) {
        cache()->forever("heartbeat:{$process->value}", now()->subMinutes(10)->getTimestamp());
    }

    cache()->forever('health:first-check', now()->subHour()->getTimestamp());
    app(BackgroundHealthService::class)->forget();
}
