<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\BalanceSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property BalanceSummaryData $resource
 */
final class BalanceSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance' => MoneyPresenter::present($this->resource->balance),
            'reserved' => MoneyPresenter::present($this->resource->reserved),
            'available' => MoneyPresenter::present($this->resource->available()),
        ];
    }
}
