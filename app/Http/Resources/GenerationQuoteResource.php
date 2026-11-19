<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\GenerationQuoteData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The price range plus whether the user can start it now (decided on the server).
 *
 * @property GenerationQuoteData $resource
 */
final class GenerationQuoteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new CostEstimateResource($this->resource->estimate))->toArray($request),
            'available' => MoneyPresenter::present($this->resource->balance->available()),
            'affordable' => $this->resource->affordable(),
            // A per-user fairness limit stops this user starting another one right now.
            'limit_reason' => $this->resource->limitReason,
        ];
    }
}
