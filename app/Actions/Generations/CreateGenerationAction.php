<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\CostEstimateData;
use App\Data\GenerationRequestData;
use App\Enums\GenerationStatus;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Exceptions\Generations\GenerationLimitReachedException;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\BalanceService;
use App\Services\Generations\GenerationQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user asked for an image (ADR-0005, ADR-0011): check they can afford the most it could cost,
 * record it, then submit it in the background.
 *
 * The estimate is always recomputed here; nothing price-related is taken from the client.
 * The user row is locked while checking and reserving, so concurrent submissions can't
 * spend the same credits twice. The client_reference (our Idempotency-Key) is persisted
 * before Pixelforge is ever called, so every retry of the submission reuses it.
 */
final readonly class CreateGenerationAction
{
    public function __construct(
        private EstimateGenerationCostAction $estimate,
        private BalanceService $balances,
        private GenerationQuotaService $quota,
    ) {}

    /**
     * @throws InsufficientCreditsException
     * @throws GenerationLimitReachedException
     */
    public function execute(User $user, GenerationRequestData $request): Generation
    {
        // Outside the transaction: it may call Pixelforge's pricing endpoint.
        $estimate = $this->estimate->execute($request);

        $generation = DB::transaction(fn (): Generation => $this->reserveAndCreate($user, $request, $estimate));
        SubmitGenerationJob::dispatch($generation);

        return $generation;
    }

    private function reserveAndCreate(User $user, GenerationRequestData $request, CostEstimateData $estimate): Generation
    {
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $this->quota->assertCanStart($locked);
        $this->balances->assertCanAfford($locked, $estimate->max->userCost);

        return $this->createPending($locked, $request, $estimate);
    }

    private function createPending(User $user, GenerationRequestData $request, CostEstimateData $estimate): Generation
    {
        return $user->generations()->create([
            'client_reference' => (string) Str::uuid(),
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_image_urls' => $request->referenceImageUrls,
            'status' => GenerationStatus::Pending,
            'rate_card' => $estimate->rateCard()->toArray(),
            'estimated_user_cost_min' => $estimate->min->userCost,
            'estimated_user_cost_max' => $estimate->max->userCost,
        ]);
    }
}
