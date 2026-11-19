<?php

declare(strict_types=1);

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Actions\Generations\SubmitGenerationToProviderAction;
use App\Data\GenerationRequestData;
use App\Enums\AlertType;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\ConcurrencyLimitException;
use App\Exceptions\Pixelforge\DailyLimitExceededException;
use App\Exceptions\Pixelforge\InvalidRequestException;
use App\Exceptions\Pixelforge\ProviderAuthException;
use App\Exceptions\Pixelforge\ProviderTimeoutException;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Jobs\SubmitGenerationJob;
use App\Mail\AdminAlertMail;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\FailureMessages;
use App\Services\Generations\GenerationLifecycleService;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePixelforgeClient;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    $this->fake = fakePixelforge();
});

function submit(Generation $generation): void
{
    app(SubmitGenerationToProviderAction::class)->execute($generation);
}

function runSubmitJob(Generation $generation): SubmitGenerationJob
{
    $job = (new SubmitGenerationJob($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

describe('accepted by Pixelforge', function () {
    it('becomes queued with the provider id', function () {
        $generation = Generation::factory()->create();

        submit($generation);

        expect($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->provider_generation_id)->toStartWith('gen_')
            ->and($generation->submission_attempts)->toBe(1)
            ->and($generation->submitted_at)->not->toBeNull();
    });

    it('sends exactly what the user asked for, keyed by its client reference', function () {
        $generation = Generation::factory()->create(['reference_image_urls' => ['https://a.test/r.png']]);

        submit($generation);

        expect($this->fake->submissions[0]['clientReference'])->toBe($generation->client_reference)
            ->and($this->fake->submissions[0]['request'])->toEqual(
                new GenerationRequestData($generation->prompt, '1024x1024', 'medium', ['https://a.test/r.png']),
            );
    });

    it('snapshots the rates Pixelforge fixed on the generation, keeping our markup', function () {
        $generation = Generation::factory()->create(['rate_card' => [
            'markup' => '0.25',
            'rates' => ['input_text' => '5.00', 'input_image' => '10.00', 'output' => '30.00', 'reasoning' => '12.50'],
        ]]);

        submit($generation);

        expect($generation->rate_card)->toEqual(['markup' => '0.25', 'rates' => FakePixelforgeClient::RATES]);
    });
});

describe('retries', function () {
    it('never creates a second billable generation when retrying after a timeout', function () {
        $generation = Generation::factory()->create();
        // Pixelforge accepted the first attempt, but the response never reached us…
        $this->fake->createGeneration(new GenerationRequestData('p', '1024x1024', 'medium'), $generation->client_reference);

        submit($generation); // …so we retry with the same Idempotency-Key.

        expect($this->fake->billableGenerationCount())->toBe(1)
            ->and($generation->status)->toBe(GenerationStatus::Queued);
    });

    it('does not resubmit a generation that already has a provider id', function () {
        $generation = Generation::factory()->queued()->create();

        submit($generation);

        expect($this->fake->submissions)->toBeEmpty();
    });

    it('releases the job for as long as Pixelforge asks, keeping the generation pending', function () {
        $this->fake->failNextCreateWith(new ConcurrencyLimitException('3 in flight', retryAfterSeconds: 5));
        $generation = Generation::factory()->create();

        $job = runSubmitJob($generation);

        $job->assertReleased();
        expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(6)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('retries overloads and timeouts too', function (Exception $exception) {
        $this->fake->failNextCreateWith($exception);

        runSubmitJob(Generation::factory()->create())->assertReleased();
    })->with([
        'overloaded' => [new ProviderUnavailableException('overloaded', 2, 'overloaded')],
        'timeout' => [new ProviderTimeoutException('timed out')],
    ]);

    it('gives up for free when the retry window closes', function () {
        $generation = Generation::factory()->create();

        (new SubmitGenerationJob($generation))->failed(new ConcurrencyLimitException('busy'));

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->failure_code->toBe('provider_busy')
            ->failure_message->toContain('not charged')
            ->actual_user_cost->toBeNull();
    });
});

describe('rejected by Pixelforge', function () {
    it('fails at once with a reason the user can act on, and is never charged', function (Exception $exception, string $code, string $message) {
        $this->fake->failNextCreateWith($exception);
        $generation = Generation::factory()->create();

        runSubmitJob($generation)->assertNotReleased();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->failure_code->toBe($code)
            ->failure_message->toContain($message)->toContain('not charged')
            ->actual_user_cost->toBeNull();
    })->with([
        'content policy' => [new InvalidRequestException('Your prompt was rejected by our content policy. You were not charged.', null, 'content_policy_violation'), 'content_policy_violation', 'Pixelforge\'s content policy doesn\'t allow this prompt'],
        'validation' => [new InvalidRequestException('The selected size is invalid.', null, 'validation_failed'), 'validation_failed', 'The selected size is invalid.'],
        'daily limit' => [new DailyLimitExceededException('Daily limit.', null, 'daily_limit_exceeded'), 'daily_limit_exceeded', 'after midnight UTC'],
        'bad API key' => [new ProviderAuthException('Invalid key.', null, 'invalid_api_key'), 'invalid_api_key', 'problem on our side'],
    ]);

    it('emails the admins when our API key or the daily limit stops everyone generating', function (Exception $exception, AlertType $alert) {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'ops@example.test']);
        $this->fake->failNextCreateWith($exception);

        submit(Generation::factory()->create());

        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === $alert && $mail->hasTo('ops@example.test'));
    })->with([
        'bad API key' => [new ProviderAuthException('Invalid key.', null, 'invalid_api_key'), AlertType::ProviderKeyRejected],
        'daily limit' => [new DailyLimitExceededException('Daily limit.', null, 'daily_limit_exceeded'), AlertType::DailyLimitReached],
    ]);

    it('does not alert anyone when one user\'s prompt is rejected', function () {
        Mail::fake();
        User::factory()->admin()->create();
        $this->fake->failNextCreateWith(new InvalidRequestException('Prompt rejected by content policy.', null, 'content_policy_violation'));

        submit(Generation::factory()->create());

        Mail::assertNothingSent();
    });
});

describe('repeated refusals', function () {
    it('gives up after 5 "overloaded" refusals instead of keeping the user waiting', function () {
        $this->fake->failNextCreateWith(...array_fill(0, 5, new ProviderUnavailableException('overloaded', 2, 'overloaded')));
        $generation = Generation::factory()->create();

        foreach (range(1, 4) as $_) {
            runSubmitJob($generation)->assertReleased();
        }
        runSubmitJob($generation)->assertNotReleased();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->failure_code->toBe('provider_unavailable')
            ->failure_message->toBe('Pixelforge is overloaded right now and didn\'t accept this generation after 5 tries. You were not charged. Please try again in a minute.');
    });

    it('keeps waiting for a free slot as long as the retry window allows', function () {
        $this->fake->failNextCreateWith(...array_fill(0, 12, new ConcurrencyLimitException('3 running', 5, 'concurrency_limit_exceeded')));
        $generation = Generation::factory()->create();

        foreach (range(1, 12) as $_) {
            runSubmitJob($generation)->assertReleased();
        }

        expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('keeps retrying timeouts: Pixelforge may have accepted it, and only a retry with the same key can tell', function () {
        $this->fake->failNextCreateWith(...array_fill(0, 8, new ProviderTimeoutException('timed out')));
        $generation = Generation::factory()->create();

        foreach (range(1, 8) as $_) {
            runSubmitJob($generation)->assertReleased();
        }

        expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    });
});

it('says "not charged" exactly once, even when Pixelforge already said it', function () {
    $messages = app(FailureMessages::class);

    expect($messages->forFailedGeneration('something_new', 'It broke. You were not charged; please retry.'))
        ->toBe('It broke. You were not charged; please retry.')
        ->and($messages->forFailedGeneration('something_new', 'It broke.'))
        ->toBe('It broke. You were not charged.')
        ->and(substr_count($messages->forRejectedSubmission(new InvalidRequestException('Bad size. You were not charged.', null, 'validation_failed')), 'not charged'))
        ->toBe(1);
});

it('alerts admins if Pixelforge runs a generation we had already given up on', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $generation = Generation::factory()->create(); // never confirmed by Pixelforge
    app(GenerationLifecycleService::class)->abandonSubmission($generation, 'provider_busy', 'Busy.');

    app(ApplyProviderUpdateAction::class)->execute((new GenerationPayloadMapper)->map(
        PixelforgeFixtures::generationPayload(['metadata' => ['client_reference' => $generation->client_reference]]),
    ));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === AlertType::AbandonedGenerationRan);
});
