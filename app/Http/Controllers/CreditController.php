<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCreditsAction;
use App\Actions\Credits\ShowCreditsAction;
use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Http\Resources\BalanceSummaryResource;
use App\Http\Resources\CreditTransactionResource;
use App\Http\Resources\MoneyPresenter;
use App\Services\Credits\CreditPackCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CreditController extends Controller
{
    public function index(Request $request, ShowCreditsAction $show, CreditPackCatalog $packs): Response
    {
        ['balance' => $balance, 'transactions' => $transactions] = $show->execute($request->user());

        return Inertia::render('credits/Index', [
            'summary' => new BalanceSummaryResource($balance),
            'transactions' => CreditTransactionResource::collection($transactions),
            'packs' => array_map(fn ($pack) => ['id' => $pack->id, 'label' => $pack->label, 'amount' => MoneyPresenter::present($pack->amount, 2)], $packs->all()),
            // A fresh key per page render: resubmitting this form can't buy twice.
            'purchaseKey' => (string) Str::uuid(),
        ]);
    }

    public function purchase(PurchaseCreditsRequest $request, PurchaseCreditsAction $purchase): RedirectResponse
    {
        $transaction = $purchase->execute($request->user(), $request->validated('pack'), $request->validated('idempotency_key'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :amount in credits.', ['amount' => $transaction->amount->format(2)])]);

        return to_route('credits.index');
    }
}
