<?php

declare(strict_types=1);

use App\Actions\Generations\ReconcileStaleGenerationsAction;
use App\Enums\AlertType;
use App\Mail\AdminAlertMail;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PixelforgeFixtures;

const ALERT_WEBHOOK_SECRET = 'whsec_alerts';

beforeEach(function () {
    Mail::fake();
    config(['pixelforge.webhook.secret' => ALERT_WEBHOOK_SECRET]);
    User::factory()->admin()->create();
    $this->fake = fakePixelforge();
});

function postSignedWebhook(array $event, string $secret = ALERT_WEBHOOK_SECRET): void
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => PixelforgeFixtures::signature($body, $secret),
    ], content: $body);
}

/**
 * A generation Pixelforge finished long ago, which reconciliation is about to discover.
 */
function finishedButSilent(): Generation
{
    $generation = Generation::factory()->queued()->create(['submitted_at' => now()->subMinutes(10), 'estimated_user_cost_max' => '1']);
    test()->fake->setGeneration((new GenerationPayloadMapper)->map(PixelforgeFixtures::generationPayload([
        'id' => $generation->provider_generation_id, 'metadata' => ['client_reference' => $generation->client_reference],
    ])));

    return $generation;
}

function alertSent(AlertType $type, bool $resolved = false): bool
{
    return Mail::sent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === $type && $mail->resolved === $resolved)->isNotEmpty();
}

it('alerts when webhooks keep failing the signature check (wrong secret)', function () {
    foreach (range(1, 4) as $_) {
        postSignedWebhook(PixelforgeFixtures::webhookEvent('ping'), secret: 'whsec_wrong');
    }
    expect(alertSent(AlertType::WebhookSignaturesRejected))->toBeFalse();

    postSignedWebhook(PixelforgeFixtures::webhookEvent('ping'), secret: 'whsec_wrong');
    expect(alertSent(AlertType::WebhookSignaturesRejected))->toBeTrue();

    postSignedWebhook(PixelforgeFixtures::webhookEvent('ping'));
    expect(alertSent(AlertType::WebhookSignaturesRejected, resolved: true))->toBeTrue();
});

it('alerts when generations only ever finish by polling (webhook URL missing)', function () {
    foreach (range(1, 3) as $_) {
        finishedButSilent();
    }

    app(ReconcileStaleGenerationsAction::class)->execute();

    expect(alertSent(AlertType::WebhooksNotArriving))->toBeTrue();
});

it('does not blame webhooks when they arrived but the queue did not process them', function () {
    foreach (range(1, 3) as $_) {
        $generation = finishedButSilent();
        WebhookEvent::factory()->create(['provider_generation_id' => $generation->provider_generation_id]);
    }

    app(ReconcileStaleGenerationsAction::class)->execute();

    expect(alertSent(AlertType::WebhooksNotArriving))->toBeFalse();
});
