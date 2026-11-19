<?php

declare(strict_types=1);

namespace App\Actions\Credits;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\CreditLedgerService;
use App\Support\Money\Usd;
use InvalidArgumentException;

/**
 * An operator's correction to a user's credits (ADR-0011): a refund (positive), or taking back
 * credits (negative). Always a new ledger entry with a reason; existing entries never change.
 */
final readonly class AdjustCreditsAction
{
    public function __construct(private CreditLedgerService $ledger) {}

    public function execute(User $user, Usd $amount, string $reason, ?string $generationId = null): CreditTransaction
    {
        if ($amount->isZero() || trim($reason) === '') {
            throw new InvalidArgumentException('An adjustment needs a non-zero amount and a reason.');
        }

        return $this->ledger->adjust($user, $amount, trim($reason), $generationId);
    }
}
