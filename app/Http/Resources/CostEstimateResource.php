<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\CostEstimateData;
use App\Data\CostLineData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A price range as the user sees it: user prices only (Pixelforge's cost and our margin stay
 * internal), with a per-token-type breakdown explaining where the range comes from.
 *
 * @property CostEstimateData $resource
 */
final class CostEstimateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'min' => MoneyPresenter::present($this->resource->min->userCost),
            'max' => MoneyPresenter::present($this->resource->max->userCost),
            'lines' => array_map($this->line(...), $this->resource->min->lines, $this->resource->max->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CostLineData $min, CostLineData $max): array
    {
        $multiplier = $this->resource->rateCard()->multiplier();

        return [
            'type' => $min->type->value,
            'label' => $min->type->label(),
            'tokens' => ['min' => $min->tokens, 'max' => $max->tokens],
            'cost' => [
                'min' => MoneyPresenter::present($min->providerCost->multipliedBy($multiplier)),
                'max' => MoneyPresenter::present($max->providerCost->multipliedBy($multiplier)),
            ],
        ];
    }
}
