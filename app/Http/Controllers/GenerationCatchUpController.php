<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Generations\CatchUpUserGenerationsAction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Called by the History page's fallback polling. A no-op unless background processing is down.
 */
final class GenerationCatchUpController extends Controller
{
    public function __invoke(Request $request, CatchUpUserGenerationsAction $catchUp): Response
    {
        $catchUp->execute($request->user());

        return response()->noContent();
    }
}
