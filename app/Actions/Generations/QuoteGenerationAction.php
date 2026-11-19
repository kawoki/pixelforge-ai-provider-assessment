<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\GenerationQuoteData;
use App\Data\GenerationRequestData;
use App\Models\User;
use App\Services\Credits\BalanceService;
use App\Services\Generations\GenerationQuotaService;

/**
 * What the Generate page shows before submitting: the price range, and whether the user can
 * afford it. Affordability is decided here, so the browser never compares money.
 */
final readonly class QuoteGenerationAction
{
    public function __construct(
        private EstimateGenerationCostAction $estimate,
        private BalanceService $balances,
        private GenerationQuotaService $quota,
    ) {}

    public function execute(User $user, GenerationRequestData $request): GenerationQuoteData
    {
        return new GenerationQuoteData($this->estimate->execute($request), $this->balances->summary($user), $this->quota->blockReason($user));
    }
}
