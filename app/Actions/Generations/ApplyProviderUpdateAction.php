<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Contracts\PixelforgeClient;
use App\Data\ProviderGenerationData;
use App\Enums\AlertType;
use App\Enums\GenerationStatus;
use App\Exceptions\Generations\GenerationNotYetKnownException;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Models\Generation;
use App\Services\Alerts\AdminAlertService;
use App\Services\Generations\FailureMessages;
use App\Services\Generations\GenerationLifecycleService;
use App\Services\Generations\SettlementService;
use Illuminate\Support\Str;

/**
 * Bring our generation in line with what Pixelforge reports (ADR-0006/0007/0010).
 * The single update path for webhooks and reconciliation polling alike.
 *
 * Safe to run any number of times, in any order: status only moves forward, terminal
 * states never change, and a generation is charged at most once.
 */
final readonly class ApplyProviderUpdateAction
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private GenerationLifecycleService $lifecycle,
        private SettlementService $settlement,
        private FailureMessages $messages,
        private AdminAlertService $alerts,
    ) {}

    /**
     * @throws GenerationNotYetKnownException
     */
    public function execute(ProviderGenerationData $provider): Generation
    {
        $generation = $this->find($provider) ?? throw GenerationNotYetKnownException::for($provider->providerGenerationId);
        $provider = $this->withUsage($provider); // before any lock: may call Pixelforge
        $this->alertIfWeGaveUpOnIt($generation, $provider);

        $this->lifecycle->recordProviderContact($generation, $provider);
        $this->apply($generation, $provider);

        return $generation;
    }

    /**
     * By Pixelforge id, or by our client_reference echoed in metadata: a webhook can
     * arrive before the create call returned and we stored the id.
     */
    private function find(ProviderGenerationData $provider): ?Generation
    {
        $reference = $provider->clientReference;

        return Generation::query()->where('provider_generation_id', $provider->providerGenerationId)->first()
            ?? ($reference !== null && Str::isUuid($reference) ? Generation::query()->where('client_reference', $reference)->first() : null);
    }

    private function apply(Generation $generation, ProviderGenerationData $provider): void
    {
        match ($provider->status) {
            GenerationStatus::Succeeded => $this->settlement->settle($generation, $provider),
            GenerationStatus::Failed => $this->lifecycle->transition($generation, GenerationStatus::Failed, [
                'failure_code' => $provider->failureCode ?? 'generation_failed',
                'failure_message' => $this->messages->forFailedGeneration($provider->failureCode, $provider->failureMessage),
                'completed_at' => $provider->completedAt ?? now(),
            ]),
            GenerationStatus::Processing => $this->lifecycle->transition($generation, GenerationStatus::Processing, ['started_at' => $provider->startedAt ?? now()]),
            GenerationStatus::Queued, GenerationStatus::Pending => $this->lifecycle->transition($generation, GenerationStatus::Queued),
        };
    }

    /**
     * We failed it locally because Pixelforge never confirmed it (e.g. every attempt timed out), yet
     * Pixelforge ran it. Status stays failed (terminal states are final), but someone must know.
     */
    private function alertIfWeGaveUpOnIt(Generation $generation, ProviderGenerationData $provider): void
    {
        $abandoned = $generation->status === GenerationStatus::Failed && $generation->provider_generation_id === null;

        if ($abandoned && $provider->status === GenerationStatus::Succeeded) {
            $this->alerts->raise(AlertType::AbandonedGenerationRan, "Generation {$generation->id} (Pixelforge {$provider->providerGenerationId}) succeeded after we gave up on it.");
        }
    }

    /**
     * Pixelforge sends usage with every succeeded generation; if one ever arrives without it,
     * fetch the generation rather than settle without knowing the cost.
     */
    private function withUsage(ProviderGenerationData $provider): ProviderGenerationData
    {
        if ($provider->status !== GenerationStatus::Succeeded || $provider->usage !== null) {
            return $provider;
        }

        $fetched = $this->pixelforge->getGeneration($provider->providerGenerationId);

        return $fetched->usage !== null ? $fetched : throw new UnexpectedProviderResponseException('Succeeded generation has no usage.');
    }
}
