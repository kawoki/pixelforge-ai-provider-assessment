<?php

declare(strict_types=1);

namespace App\Actions\Credits;

use App\Data\BalanceSummaryData;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credits\BalanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Everything the Credits page shows: the balance and the user's own ledger, newest first.
 */
final readonly class ShowCreditsAction
{
    public const int PER_PAGE = 20;

    public function __construct(private BalanceService $balances) {}

    /**
     * @return array{balance: BalanceSummaryData, transactions: LengthAwarePaginator<int, CreditTransaction>}
     */
    public function execute(User $user): array
    {
        return [
            'balance' => $this->balances->summary($user),
            'transactions' => $user->creditTransactions()->with('generation:id,prompt')->latest()->latest('id')->paginate(self::PER_PAGE),
        ];
    }
}
