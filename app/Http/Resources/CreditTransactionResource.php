<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger line as its owner sees it.
 *
 * @property CreditTransaction $resource
 */
final class CreditTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type->value,
            'label' => $this->label(),
            'amount' => MoneyPresenter::present($this->resource->amount),
            'balance_after' => MoneyPresenter::present($this->resource->balance_after),
            'generation' => $this->resource->generation === null ? null : [
                'id' => $this->resource->generation->id,
                'prompt' => $this->resource->generation->prompt,
            ],
            'created_at' => $this->resource->created_at->toIso8601String(),
        ];
    }

    private function label(): string
    {
        return match ($this->resource->type) {
            CreditTransactionType::Purchase => 'Bought credits',
            CreditTransactionType::Charge => 'Generation',
            CreditTransactionType::Adjustment => 'Adjustment',
        };
    }
}
