<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as its owner sees it. Never includes Pixelforge's cost or our margin.
 *
 * @property Generation $resource
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'prompt' => $this->resource->prompt,
            'size' => $this->resource->size,
            'quality' => $this->resource->quality,
            'reference_image_count' => count($this->resource->reference_image_urls),
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'is_finished' => $this->resource->status->isTerminal(),
            'failure_message' => $this->resource->failure_message,
            'image' => $this->image(),
            ...$this->costs(),
            'created_at' => $this->resource->created_at->toIso8601String(),
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Pixelforge's signed image URL, while it still works (24 h after completion). Only https
     * URLs are passed on: the URL ends up in an href, so never let another scheme through.
     *
     * @return array{url: string|null, expires_at: string|null, expired: bool}|null
     */
    private function image(): ?array
    {
        $url = $this->resource->image_url;

        if ($url === null || ! str_starts_with($url, 'https://')) {
            return null;
        }

        $expired = $this->resource->image_expires_at?->isPast() ?? false;

        return ['url' => $expired ? null : $url, 'expires_at' => $this->resource->image_expires_at?->toIso8601String(), 'expired' => $expired];
    }

    /**
     * What the user was quoted, and (once succeeded) what they were charged.
     *
     * @return array<string, mixed>
     */
    private function costs(): array
    {
        return [
            'estimated_cost' => [
                'min' => MoneyPresenter::present($this->resource->estimated_user_cost_min),
                'max' => MoneyPresenter::present($this->resource->estimated_user_cost_max),
            ],
            'actual_cost' => MoneyPresenter::present($this->resource->actual_user_cost),
        ];
    }
}
