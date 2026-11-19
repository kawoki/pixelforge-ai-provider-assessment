<?php

declare(strict_types=1);

namespace App\Actions\Credits;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\CreditLedgerService;
use App\Services\Credits\CreditPackCatalog;
use InvalidArgumentException;

/**
 * "Buy credits" (ADR-0011). A fake purchase: no payment, but recorded in the ledger exactly
 * like a real one would be, and idempotent per form submission.
 */
final readonly class PurchaseCreditsAction
{
    public function __construct(
        private CreditPackCatalog $packs,
        private CreditLedgerService $ledger,
    ) {}

    public function execute(User $user, string $packId, string $idempotencyKey): CreditTransaction
    {
        $pack = $this->packs->find($packId) ?? throw new InvalidArgumentException("Unknown credit pack [{$packId}].");

        return $this->ledger->purchase($user, $pack, $idempotencyKey);
    }
}
