<?php

declare(strict_types=1);

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Actions\Generations\SubmitGenerationToProviderAction;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\PixelforgeFixtures;

const WEBHOOK_SECRET = 'whsec_test_webhooks';
const DOCS_GENERATION_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

beforeEach(function () {
    config(['pixelforge.webhook.secret' => WEBHOOK_SECRET]);
    $this->fake = fakePixelforge();
});

/**
 * POST a webhook exactly as Pixelforge would: raw JSON body, signed with our secret.
 *
 * @param  array<string, mixed>  $event
 */
function deliver(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? PixelforgeFixtures::signature($body, WEBHOOK_SECRET)];

    return test()->call('POST', route('webhooks.pixelforge'), server: $server, content: $body);
}

/**
 * The docs' generation (1536x1024, high, 1 reference image) as our app recorded it on acceptance.
 *
 * @param  array<string, mixed>  $attributes
 */
function acceptedGeneration(array $attributes = []): Generation
{
    return Generation::factory()->queued()->create([
        'provider_generation_id' => DOCS_GENERATION_ID, 'size' => '1536x1024', 'quality' => 'high',
        'estimated_user_cost_min' => '0.300000000000', 'estimated_user_cost_max' => '0.460000000000', ...$attributes,
    ]);
}

function charges(): int
{
    return CreditTransaction::query()->where('type', CreditTransactionType::Charge)->count();
}

describe('a generation succeeds', function () {
    it('records exactly what it cost: usage × its own rates, plus 25%', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'))->assertNoContent();

        // 12×$5 + 765×$10 + 6,450×$40 + 3,184×$12.50 per 1M tokens = $0.30551; × 1.25 = $0.3818875
        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Succeeded)
            ->actual_provider_cost->toDecimalString()->toBe('0.305510000000')
            ->actual_user_cost->toDecimalString()->toBe('0.381887500000')
            ->usage->toEqual(['input_text' => 12, 'input_image' => 765, 'output' => 6450, 'reasoning' => 3184])
            ->image_url->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->image_expires_at->toIso8601String()->toBe('2026-11-05T14:03:29+00:00')
            ->completed_at->toIso8601String()->toBe('2026-11-04T14:03:29+00:00');
    });

    it('writes one ledger charge and moves the balance by the same amount', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));

        $charge = CreditTransaction::query()->sole();
        expect($charge->generation_id)->toBe($generation->id)
            ->and($charge->amount->toDecimalString())->toBe('-0.381887500000')
            ->and($charge->idempotency_key)->toBe("charge:{$generation->id}")
            ->and($generation->user->fresh()->balance->toDecimalString())->toBe('-0.381887500000');
    });

    it('bills at the rates Pixelforge fixed on the generation, not today\'s pricing', function () {
        $generation = acceptedGeneration(['rate_card' => ['markup' => '0.25', 'rates' => [
            'input_text' => '1.00', 'input_image' => '1.00', 'output' => '1.00', 'reasoning' => '1.00',
        ]]]);

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));

        expect($generation->fresh()->actual_provider_cost->toDecimalString())->toBe('0.305510000000');
    });

    it('fetches the generation if a success ever arrives without usage', function () {
        acceptedGeneration();
        $this->fake->setGeneration(app(GenerationPayloadMapper::class)->map(PixelforgeFixtures::generationPayload()));

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded', ['usage' => null]));

        expect(charges())->toBe(1);
    });

    it('still charges the real cost, loudly, if it ever exceeds the estimate', function () {
        Log::spy();
        $generation = acceptedGeneration(['estimated_user_cost_max' => '0.300000000000']);

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));

        expect($generation->fresh()->actual_user_cost->toDecimalString())->toBe('0.381887500000');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'exceeded the estimate'))->once();
    });
});

describe('at-least-once and out-of-order delivery', function () {
    it('records an event delivered twice only once', function () {
        acceptedGeneration();
        $event = PixelforgeFixtures::webhookEvent('generation.succeeded');

        deliver($event)->assertNoContent();
        deliver($event)->assertNoContent();

        expect(WebhookEvent::count())->toBe(1)->and(charges())->toBe(1);
    });

    it('charges once even when different events report the same success', function () {
        acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));
        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));

        expect(WebhookEvent::count())->toBe(2)->and(charges())->toBe(1);
    });

    it('never moves a finished generation backwards', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));
        deliver(PixelforgeFixtures::webhookEvent('generation.processing', PixelforgeFixtures::inFlightGeneration('processing')))->assertNoContent();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Succeeded)
            ->actual_user_cost->not->toBeNull()
            ->image_url->not->toBeNull();
    });

    it('ignores a failure reported after success (first terminal state wins)', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));
        deliver(PixelforgeFixtures::webhookEvent('generation.failed', ['status' => 'failed', 'usage' => null, 'error' => ['code' => 'internal_error', 'message' => 'x']]));

        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)->and(charges())->toBe(1);
    });

    it('follows the normal order too', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.processing', PixelforgeFixtures::inFlightGeneration('processing')));
        expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and($generation->fresh()->started_at->toIso8601String())->toBe('2026-11-04T14:03:13+00:00');

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded'));
        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });
});

describe('a generation fails', function () {
    it('is never charged and tells the user why', function () {
        $generation = acceptedGeneration();

        deliver(PixelforgeFixtures::webhookEvent('generation.failed', [
            'status' => 'failed', 'usage' => null, 'image_url' => null,
            'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating.'],
        ]));

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->failure_code->toBe('internal_error')
            ->failure_message->toContain('not charged')
            ->actual_user_cost->toBeNull()
            ->and(charges())->toBe(0)
            ->and($generation->user->fresh()->balance->isZero())->toBeTrue();
    });
});

describe('webhooks that arrive early', function () {
    it('matches a generation whose create call has not returned yet, by metadata', function () {
        $generation = Generation::factory()->create(); // pending, no provider id yet

        deliver(PixelforgeFixtures::webhookEvent('generation.succeeded', ['metadata' => ['client_reference' => $generation->client_reference]]));

        expect($generation->fresh())
            ->provider_generation_id->toBe(DOCS_GENERATION_ID)
            ->status->toBe(GenerationStatus::Succeeded);
    });

    it('does not resubmit a generation a webhook already linked', function () {
        $generation = Generation::factory()->create();
        deliver(PixelforgeFixtures::webhookEvent('generation.processing', PixelforgeFixtures::inFlightGeneration('processing', [
            'metadata' => ['client_reference' => $generation->client_reference],
        ])));

        app(SubmitGenerationToProviderAction::class)->execute($generation->fresh());

        expect($this->fake->submissions)->toBeEmpty();
    });

    it('keeps an event for an unknown generation, and processes it when Pixelforge redelivers', function () {
        $event = PixelforgeFixtures::webhookEvent('generation.succeeded');

        deliver($event)->assertNoContent();
        expect(WebhookEvent::sole())->processed_at->toBeNull()->processing_error->toContain(DOCS_GENERATION_ID);

        acceptedGeneration();
        deliver($event)->assertNoContent(); // automatic retry or dashboard "resend"

        expect(WebhookEvent::sole()->processed_at)->not->toBeNull()->and(charges())->toBe(1);
    });
});

describe('what the endpoint accepts', function () {
    it('acknowledges ping events and does nothing else', function () {
        deliver(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])->assertNoContent();

        expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    it('rejects requests not signed by Pixelforge and stores nothing', function (Closure $signature) {
        acceptedGeneration();
        $event = PixelforgeFixtures::webhookEvent('generation.succeeded');

        deliver($event, $signature(json_encode($event)))->assertBadRequest();

        expect(WebhookEvent::count())->toBe(0)->and(charges())->toBe(0);
    })->with([
        'wrong secret' => [fn (string $body) => PixelforgeFixtures::signature($body, 'whsec_attacker')],
        'replayed' => [fn (string $body) => PixelforgeFixtures::signature($body, WEBHOOK_SECRET, time() - 3600)],
        'unsigned' => [fn () => ''],
    ]);

    it('rejects a signed body that is not a Pixelforge event', function () {
        deliver(['hello' => 'world'])->assertBadRequest();

        expect(WebhookEvent::count())->toBe(0);
    });

    it('lives outside the web middleware group (no session, no CSRF)', function () {
        $middleware = Route::getRoutes()->getByName('webhooks.pixelforge')->gatherMiddleware();

        expect($middleware)->not->toContain('web');
    });
});

it('applies updates idempotently however often they are replayed (webhooks and polling share this path)', function () {
    $generation = acceptedGeneration();
    $succeeded = app(GenerationPayloadMapper::class)->map(PixelforgeFixtures::generationPayload());

    foreach (range(1, 3) as $_) {
        app(ApplyProviderUpdateAction::class)->execute($succeeded);
    }

    expect(charges())->toBe(1)->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('decides from the generation object, not the event type', function () {
    $generation = acceptedGeneration();

    // A retried "processing" event can carry a generation that has since succeeded.
    deliver(PixelforgeFixtures::webhookEvent('generation.processing'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)->and(charges())->toBe(1);
});
