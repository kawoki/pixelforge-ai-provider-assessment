<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Data\ProviderGenerationData;
use App\Data\RateCardData;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only writer of generation status (ADR-0006). Every change runs under a row lock,
 * moves forward only, and never leaves a terminal state. Every change is pushed to the
 * owner's browser once committed (ADR-0009).
 */
final readonly class GenerationLifecycleService
{
    public function __construct(private GenerationUpdatePublisher $publisher) {}

    /**
     * Pixelforge accepted the generation: remember its id and the rates it will bill at.
     * Safe if a webhook already moved the generation further: status only moves forward.
     */
    public function recordAcceptance(Generation $generation, ProviderGenerationData $provider, RateCardData $rateCard): void
    {
        $queued = $this->locked($generation, function (Generation $locked) use ($provider, $rateCard): bool {
            $locked->provider_generation_id ??= $provider->providerGenerationId;
            $locked->submitted_at ??= now();
            $queued = $locked->status->canTransitionTo(GenerationStatus::Queued);

            if ($queued) {
                $locked->fill(['status' => GenerationStatus::Queued, 'rate_card' => $rateCard->toArray()]);
            }

            return $locked->save() && $queued;
        });

        $this->announceIf($queued, $generation);
    }

    /**
     * Fail a generation that has not finished yet. Failed generations are never charged.
     */
    public function fail(Generation $generation, string $code, string $message): bool
    {
        return $this->transition($generation, GenerationStatus::Failed, self::failure($code, $message));
    }

    /**
     * Give up on a submission Pixelforge never accepted. If it was accepted after all
     * (it has a provider id), leave it alone: it is running and webhooks will finish it.
     */
    public function abandonSubmission(Generation $generation, string $code, string $message): bool
    {
        return $this->announceIf($this->locked($generation, fn (Generation $locked): bool => $locked->provider_generation_id === null
            && $this->apply($locked, GenerationStatus::Failed, self::failure($code, $message))), $generation);
    }

    /**
     * Pixelforge told us about this generation (webhook or poll): link its id if a webhook
     * beat the create response, and note the contact (reconciliation uses it, ADR-0010).
     */
    public function recordProviderContact(Generation $generation, ProviderGenerationData $provider): void
    {
        $this->locked($generation, function (Generation $locked) use ($provider): void {
            $locked->provider_generation_id ??= $provider->providerGenerationId;
            $locked->last_provider_event_at = now();
            $locked->save();
        });
    }

    /**
     * Apply a forward-only status change. Returns false (and changes nothing) otherwise.
     *
     * $effects runs only when the change is allowed, inside the same lock and transaction,
     * and returns extra attributes. Settlement uses it so a charge and `succeeded` are atomic.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(Generation): array<string, mixed>)|null  $effects
     */
    public function transition(Generation $generation, GenerationStatus $to, array $attributes = [], ?Closure $effects = null): bool
    {
        return $this->announceIf($this->locked($generation, fn (Generation $locked): bool => $this->apply($locked, $to, $attributes, $effects)), $generation);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(Generation): array<string, mixed>)|null  $effects
     */
    private function apply(Generation $locked, GenerationStatus $to, array $attributes, ?Closure $effects = null): bool
    {
        if (! $locked->status->canTransitionTo($to)) {
            Log::info('Ignored generation status change.', ['generation' => $locked->id, 'from' => $locked->status->value, 'to' => $to->value]);

            return false;
        }

        $extra = $effects === null ? [] : $effects($locked);

        return $locked->fill([...$attributes, ...$extra, 'status' => $to])->save();
    }

    private function announceIf(bool $changed, Generation $generation): bool
    {
        if ($changed) {
            $this->publisher->publish($generation);
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(string $code, string $message): array
    {
        return ['failure_code' => $code, 'failure_message' => $message, 'completed_at' => now()];
    }

    /**
     * Run $change on a row-locked copy, then refresh the caller's instance.
     *
     * @template T
     *
     * @param  Closure(Generation): T  $change
     * @return T
     */
    private function locked(Generation $generation, Closure $change): mixed
    {
        $result = DB::transaction(fn () => $change(Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail()));
        $generation->refresh();

        return $result;
    }
}
