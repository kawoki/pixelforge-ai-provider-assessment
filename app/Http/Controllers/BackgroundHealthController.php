<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Health\CheckBackgroundHealthAction;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * For external uptime monitors (ADR-0015): 503 when the scheduler or queue worker is down.
 * The only reliable way to notice that the scheduler itself died while nobody is using the app.
 * Deliberately says nothing about internals beyond which process is affected.
 */
final class BackgroundHealthController extends Controller
{
    public function __invoke(CheckBackgroundHealthAction $check): JsonResponse
    {
        $health = $check->execute();

        return response()->json(
            ['status' => $health->degraded() ? 'degraded' : 'ok', ...$health->toArray()],
            $health->degraded() ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
        );
    }
}
