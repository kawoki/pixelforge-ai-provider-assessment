<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Webhooks\ReceiveWebhookEventAction;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives signed Pixelforge webhooks (signature checked by middleware). Answers within
 * milliseconds: storing the event is all that happens here (Pixelforge allows 10 s).
 */
final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveWebhookEventAction $receive): Response
    {
        try {
            $receive->execute($request->getContent());
        } catch (UnexpectedProviderResponseException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return response()->noContent();
    }
}
