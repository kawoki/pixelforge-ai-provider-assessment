<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Generations\QuoteGenerationAction;
use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Http\Resources\GenerationQuoteResource;

final class GenerationEstimateController extends Controller
{
    public function __invoke(EstimateGenerationRequest $request, QuoteGenerationAction $quote): GenerationQuoteResource
    {
        return new GenerationQuoteResource($quote->execute($request->user(), $request->toData()));
    }
}
