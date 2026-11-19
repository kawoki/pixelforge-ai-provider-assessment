<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\CostEstimateData;
use App\Data\GenerationRequestData;
use App\Services\Pricing\PricingCatalogRepository;
use App\Services\Pricing\PricingService;

/**
 * Price range for a generation before it is submitted (ADR-0004).
 */
final readonly class EstimateGenerationCostAction
{
    public function __construct(
        private PricingCatalogRepository $catalog,
        private PricingService $pricing,
    ) {}

    public function execute(GenerationRequestData $request): CostEstimateData
    {
        return $this->pricing->estimate($request, $this->catalog->current());
    }
}
