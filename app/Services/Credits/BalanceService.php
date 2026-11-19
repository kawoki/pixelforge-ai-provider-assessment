<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Data\BalanceSummaryData;
use App\Enums\GenerationStatus;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Models\User;
use App\Support\Money\Usd;

/**
 * What a user can spend (ADR-0011): balance minus the estimate maximum of every generation
 * still running. Reserving the maximum means a settled charge never takes a balance below zero
 * while Pixelforge's published token rules hold.
 */
final readonly class BalanceService
{
    public function summary(User $user): BalanceSummaryData
    {
        return new BalanceSummaryData($user->balance, $this->reserved($user));
    }

    /**
     * Call with a row-locked user (see CreateGenerationAction), or two concurrent
     * submissions could both spend the same credits.
     *
     * @throws InsufficientCreditsException
     */
    public function assertCanAfford(User $lockedUser, Usd $maximumCost): void
    {
        $summary = $this->summary($lockedUser);

        if (! $summary->canAfford($maximumCost)) {
            throw new InsufficientCreditsException($maximumCost, $summary->available());
        }
    }

    /**
     * Summed in Postgres and read back as exact text: a PHP float must never touch money.
     */
    private function reserved(User $user): Usd
    {
        $sum = $user->generations()
            ->whereIn('status', GenerationStatus::inFlight())
            ->selectRaw('COALESCE(SUM(estimated_user_cost_max), 0)::text AS reserved')
            ->value('reserved');

        return Usd::of((string) $sum);
    }
}
