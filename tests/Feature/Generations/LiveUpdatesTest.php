<?php

declare(strict_types=1);

use App\Data\ProviderGenerationData;
use App\Data\RateCardData;
use App\Enums\GenerationStatus;
use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationLifecycleService;
use Database\Factories\GenerationFactory;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('pushes every status change to the owner, after it is committed', function () {
    Event::fake([GenerationUpdated::class]);
    $generation = Generation::factory()->queued()->create();

    app(GenerationLifecycleService::class)->transition($generation, GenerationStatus::Processing);

    Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $event) => $event->userId === $generation->user_id
        && $event->generationId === $generation->id
        && $event->status === 'processing');
});

it('pushes when Pixelforge accepts a generation', function () {
    Event::fake([GenerationUpdated::class]);
    $generation = Generation::factory()->create();

    app(GenerationLifecycleService::class)->recordAcceptance($generation, new ProviderGenerationData('gen_1', GenerationStatus::Queued), RateCardData::fromArray(GenerationFactory::testRateCard()));

    Event::assertDispatched(GenerationUpdated::class, fn (GenerationUpdated $event) => $event->status === 'queued');
});

it('stays quiet when nothing changed', function () {
    Event::fake([GenerationUpdated::class]);
    $generation = Generation::factory()->succeeded()->create();

    app(GenerationLifecycleService::class)->transition($generation, GenerationStatus::Processing);
    app(GenerationLifecycleService::class)->recordProviderContact($generation, new ProviderGenerationData('gen_1', GenerationStatus::Succeeded));

    Event::assertNotDispatched(GenerationUpdated::class);
});

it('broadcasts on the owner\'s private channel with ids only', function () {
    $event = new GenerationUpdated(42, 'gen-ulid', 'succeeded');

    expect($event->broadcastOn()->name)->toBe('private-App.Models.User.42')
        ->and($event->broadcastAs())->toBe('generation.updated')
        ->and($event->broadcastWith())->toBe(['id' => 'gen-ulid', 'status' => 'succeeded']);
});

it('keeps the status change when the WebSocket server is down (browsers fall back to polling)', function () {
    Broadcast::extend('failing', fn () => new class extends Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Reverb is down');
        }
    });
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    Log::spy();
    $generation = Generation::factory()->queued()->create();

    $changed = app(GenerationLifecycleService::class)->transition($generation, GenerationStatus::Processing);

    expect($changed)->toBeTrue()->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'fall back to polling'))->once();
});

it('lets users subscribe only to their own channel', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    require base_path('routes/channels.php'); // register the channel rules on the reverb driver
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])->assertOk();
    $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$other->id}"])->assertForbidden();
});
