<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Data\CostBreakdownData;
use App\Data\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Credits\CreditLedgerService;
use App\Services\Pixelforge\ProviderUsageCache;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Records what a succeeded generation actually cost, exactly once (ADR-0008).
 *
 * Runs inside the `→ succeeded` transition: row lock + transaction + forward-only status
 * mean only the first report of success charges; every later one is ignored. The ledger's
 * unique `charge:{id}` key is the database's last line of defence.
 */
final readonly class SettlementService
{
    public function __construct(
        private GenerationLifecycleService $lifecycle,
        private PricingService $pricing,
        private CreditLedgerService $ledger,
        private ProviderUsageCache $usageCache,
    ) {}

    public function settle(Generation $generation, ProviderGenerationData $provider): bool
    {
        $settled = $this->lifecycle->transition(
            $generation,
            GenerationStatus::Succeeded,
            $this->result($provider),
            fn (Generation $locked): array => $this->charge($locked, $provider),
        );

        if ($settled) {
            // The spend report must not compare this new charge with /v1/usage figures fetched before it.
            DB::afterCommit(fn () => $this->usageCache->invalidate());
        }

        return $settled;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(ProviderGenerationData $provider): array
    {
        return array_filter([
            'usage' => $provider->usage?->toArray(),
            'image_url' => $provider->imageUrl,
            'image_expires_at' => $provider->imageExpiresAt,
            'started_at' => $provider->startedAt,
            // Pixelforge bills on the UTC day it completed; keep its timestamp so reports match /v1/usage.
            'completed_at' => $provider->completedAt ?? now(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(Generation $locked, ProviderGenerationData $provider): array
    {
        $cost = $this->cost($locked, $provider);
        $this->ledger->charge($locked, $cost->userCost);
        $this->warnIfAboveEstimate($locked, $cost);

        return [
            'rate_card' => $cost->rateCard->toArray(),
            'actual_provider_cost' => $cost->providerCost,
            'actual_user_cost' => $cost->userCost,
        ];
    }

    /**
     * Usage × the rates Pixelforge fixed on this generation (sent with every generation object),
     * with the markup snapshotted when the user submitted.
     */
    private function cost(Generation $locked, ProviderGenerationData $provider): CostBreakdownData
    {
        $usage = $provider->usage ?? throw new LogicException("Cannot settle generation [{$locked->id}] without usage.");
        $snapshot = $locked->rateCardSnapshot();

        return $this->pricing->actual($usage, $provider->rates === null ? $snapshot : $snapshot->withRates($provider->rates));
    }

    /**
     * Never happens while Pixelforge's published token rules hold; we still charge the real cost.
     */
    private function warnIfAboveEstimate(Generation $locked, CostBreakdownData $cost): void
    {
        if ($cost->userCost->isGreaterThan($locked->estimated_user_cost_max)) {
            Log::warning('Actual cost exceeded the estimate maximum.', [
                'generation' => $locked->id, 'actual' => (string) $cost->userCost, 'estimated_max' => (string) $locked->estimated_user_cost_max,
            ]);
        }
    }
}
