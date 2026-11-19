<?php

declare(strict_types=1);

use App\Data\BackgroundHealthData;
use App\Enums\BackgroundProcess;
use App\Jobs\RecordQueueHeartbeatJob;
use App\Models\User;
use App\Services\Health\BackgroundHealthService;
use App\Services\Health\HeartbeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function health(): BackgroundHealthData
{
    return app(BackgroundHealthService::class)->check();
}

it('is healthy while both processes beat', function () {
    backgroundIsUp();

    expect(health()->degraded())->toBeFalse();
});

it('notices the scheduler stopped', function () {
    backgroundIsDown(BackgroundProcess::Scheduler);

    expect(health())->schedulerRunning->toBeFalse()->and(health()->summary())->toContain('scheduler is not running');
});

it('notices the queue worker stopped while the scheduler keeps dispatching heartbeats', function () {
    backgroundIsDown(BackgroundProcess::QueueWorker);

    expect(health())->schedulerRunning->toBeTrue()->queueRunning->toBeFalse();
});

it('notices jobs piling up even when every heartbeat looks fine', function () {
    config(['queue.default' => 'database']);
    backgroundIsUp();
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp(),
    ]);
    app(BackgroundHealthService::class)->forget();

    expect(health()->queueRunning)->toBeFalse();
});

it('gives freshly deployed processes a few minutes before calling it an outage', function () {
    expect(health()->degraded())->toBeFalse();

    $this->travel(4)->minutes();
    app(BackgroundHealthService::class)->forget();

    expect(health()->degraded())->toBeTrue();
});

it('schedules both heartbeats every minute', function () {
    $names = collect(app(Schedule::class)->events())->map->description->filter()->values()->all();

    expect($names)->toContain('heartbeat:scheduler', 'heartbeat:queue');
});

it('records the queue heartbeat when a worker runs the job', function () {
    app()->call([new RecordQueueHeartbeatJob, 'handle']);

    expect(app(HeartbeatService::class)->lastBeat(BackgroundProcess::QueueWorker))->not->toBeNull();
});

describe('reporting', function () {
    it('answers an uptime monitor holding the token with 200 or 503', function () {
        config(['monitoring.health_token' => 'monitor-secret']);
        $monitor = ['Authorization' => 'Bearer monitor-secret'];

        backgroundIsUp();
        $this->getJson(route('health.background'), $monitor)->assertOk()->assertJson(['status' => 'ok']);

        backgroundIsDown(BackgroundProcess::QueueWorker);
        $this->getJson(route('health.background'), $monitor)->assertServiceUnavailable()->assertJson(['status' => 'degraded', 'queue' => false]);
    });

    it('is closed to everyone else, and to everyone when no token is configured', function () {
        config(['monitoring.health_token' => 'monitor-secret']);
        $this->getJson(route('health.background'))->assertNotFound();
        $this->getJson(route('health.background'), ['Authorization' => 'Bearer guess'])->assertNotFound();

        config(['monitoring.health_token' => null]);
        $this->getJson(route('health.background'), ['Authorization' => 'Bearer '])->assertNotFound();
    });

    it('tells signed-in pages whether to switch to fallback polling', function () {
        backgroundIsDown();

        $this->actingAs(User::factory()->create())
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('background.degraded', true));
    });
});
