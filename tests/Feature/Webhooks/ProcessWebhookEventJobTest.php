<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use Tests\Support\PixelforgeFixtures;

beforeEach(fn () => fakePixelforge());

function webhookEventRecord(): WebhookEvent
{
    $payload = PixelforgeFixtures::webhookEvent('generation.succeeded');

    return WebhookEvent::query()->create([
        'provider_event_id' => $payload['id'], 'type' => $payload['type'],
        'provider_generation_id' => $payload['data']['object']['id'], 'payload' => $payload,
    ]);
}

it('waits and retries when the generation is not known yet', function () {
    $job = (new ProcessWebhookEventJob(webhookEventRecord()))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 5);
    expect($job->event->fresh())->processed_at->toBeNull()->processing_error->toContain('No local generation');
});

it('records why it finally gave up', function () {
    $event = webhookEventRecord();

    (new ProcessWebhookEventJob($event))->failed(new RuntimeException('Pixelforge sent nonsense.'));

    expect($event->fresh()->processing_error)->toBe('Pixelforge sent nonsense.');
});

it('does nothing for an event that was already processed', function () {
    $event = webhookEventRecord();
    $event->forceFill(['processed_at' => now()])->save();
    $job = (new ProcessWebhookEventJob($event))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($event->fresh()->attempts)->toBe(0);
});
