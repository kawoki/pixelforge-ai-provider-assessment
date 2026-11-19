<?php

declare(strict_types=1);

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Actions\Generations\ReconcileStaleGenerationsAction;
use App\Data\ReconciliationResultData;
use App\Enums\AlertType;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\RateLimitedException;
use App\Jobs\SubmitGenerationJob;
use App\Mail\AdminAlertMail;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    $this->fake = fakePixelforge();
});

function reconcile(): ReconciliationResultData
{
    return app(ReconcileStaleGenerationsAction::class)->execute();
}

/**
 * A generation Pixelforge accepted $minutesAgo minutes ago and we have heard nothing about since.
 *
 * @param  array<string, mixed>  $attributes
 */
function silentGeneration(int $minutesAgo = 5, array $attributes = []): Generation
{
    return Generation::factory()->queued()->create([
        'submitted_at' => now()->subMinutes($minutesAgo),
        'estimated_user_cost_max' => '0.460000000000',
        ...$attributes,
    ]);
}

/**
 * Make Pixelforge report this generation in the given state (docs payload, our ids).
 *
 * @param  array<string, mixed>  $overrides
 */
function pixelforgeReports(Generation $generation, array $overrides = []): void
{
    test()->fake->setGeneration((new GenerationPayloadMapper)->map(PixelforgeFixtures::generationPayload([
        'id' => $generation->provider_generation_id,
        'metadata' => ['client_reference' => $generation->client_reference],
        ...$overrides,
    ])));
}

it('settles a generation whose webhooks never arrived, charging exactly once', function () {
    $generation = silentGeneration();
    pixelforgeReports($generation);

    reconcile();
    reconcile();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->actual_user_cost->toDecimalString()->toBe('0.381887500000')
        ->and(CreditTransaction::count())->toBe(1);
});

it('records failures it finds, free of charge', function () {
    $generation = silentGeneration();
    pixelforgeReports($generation, ['status' => 'failed', 'usage' => null, 'error' => ['code' => 'internal_error', 'message' => 'x']]);

    reconcile();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)->and(CreditTransaction::count())->toBe(0);
});

it('charges once when the webhook and the poll both report success', function () {
    $generation = silentGeneration();
    pixelforgeReports($generation);
    reconcile();

    app(ApplyProviderUpdateAction::class)->execute($this->fake->getGeneration((string) $generation->provider_generation_id));

    expect(CreditTransaction::count())->toBe(1);
});

it('leaves alone generations it heard from recently, or that are finished', function () {
    silentGeneration(minutesAgo: 1);
    Generation::factory()->succeeded()->create(['submitted_at' => now()->subHour()]);
    Generation::factory()->failed()->create(['submitted_at' => now()->subHour()]);

    expect(reconcile()->polled)->toBe(0)->and($this->fake->retrievals)->toBeEmpty();
});

it('waits before polling a still-running generation again', function () {
    $generation = silentGeneration();
    pixelforgeReports($generation, PixelforgeFixtures::inFlightGeneration('processing'));

    reconcile();
    reconcile();

    expect($this->fake->retrievals)->toHaveCount(1)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);

    $this->travel(3)->minutes();
    reconcile();

    expect($this->fake->retrievals)->toHaveCount(2);
});

it('polls the longest-silent generations first, a small batch per run', function () {
    config(['pixelforge.reconciliation.batch_size' => 2]);
    $newest = silentGeneration(minutesAgo: 3);
    $oldest = silentGeneration(minutesAgo: 30);
    $middle = silentGeneration(minutesAgo: 10);
    foreach ([$newest, $oldest, $middle] as $generation) {
        pixelforgeReports($generation, PixelforgeFixtures::inFlightGeneration('processing'));
    }

    reconcile();

    expect($this->fake->retrievals)->toBe([$oldest->provider_generation_id, $middle->provider_generation_id]);
});

it('stops for this run when Pixelforge says we are asking too often', function () {
    $first = silentGeneration(minutesAgo: 10);
    silentGeneration(minutesAgo: 5);
    $this->fake->setGeneration(new RateLimitedException('slow down', 10), $first->provider_generation_id);

    $result = reconcile();

    expect($result->stoppedByRateLimit)->toBeTrue()->and($this->fake->retrievals)->toHaveCount(1);
});

it('skips a generation Pixelforge cannot find, without starving the others', function () {
    $missing = silentGeneration(minutesAgo: 10);
    $other = silentGeneration(minutesAgo: 5);
    $this->fake->setGeneration(new GenerationNotFoundException('not_found'), $missing->provider_generation_id);
    pixelforgeReports($other);

    $result = reconcile();

    expect($result->errors)->toBe(1)
        ->and($other->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($missing->fresh()->last_provider_event_at)->not->toBeNull();
});

it('requeues submissions whose job was lost, and only those', function () {
    Queue::fake();
    $lost = Generation::factory()->create(['created_at' => now()->subHour()]);
    Generation::factory()->create(['created_at' => now()->subMinutes(5)]); // its job may still be retrying

    expect(reconcile()->requeued)->toBe(1);
    Queue::assertPushed(SubmitGenerationJob::class, 1);
    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generation->is($lost));
});

it('runs every minute from the scheduler, never overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'pixelforge:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('can be run by hand', function () {
    $generation = silentGeneration();
    pixelforgeReports($generation);

    $this->artisan('pixelforge:reconcile')->expectsOutputToContain('Polled 1 stale generation(s)')->assertSuccessful();
});

it('gives up on a generation Pixelforge keeps saying it doesn\'t know, free of charge, and tells admins', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $generation = silentGeneration();
    $this->fake->setGeneration(new GenerationNotFoundException('not_found'), $generation->provider_generation_id);

    foreach (range(1, 4) as $_) {
        reconcile();
        $this->travel(3)->minutes();
    }
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);

    reconcile();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->failure_code->toBe('not_found_at_provider')
        ->failure_message->toContain('not charged');
    Mail::assertSent(AdminAlertMail::class, fn ($mail) => $mail->type === AlertType::GenerationLostAtProvider);
});
