<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Webhooks\InvalidWebhookSignatureException;
use App\Services\Alerts\WebhookDeliveryMonitor;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook requests that are not signed by Pixelforge (ADR-0007). Nothing is stored for them.
 */
final readonly class VerifyPixelforgeSignature
{
    public function __construct(
        private WebhookSignatureVerifier $verifier,
        private WebhookDeliveryMonitor $monitor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->verifier->verify($request->getContent(), $request->header('Pixelforge-Signature'));
        } catch (InvalidWebhookSignatureException $e) {
            Log::warning('Rejected a Pixelforge webhook with an invalid signature.', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);
            $this->monitor->recordRejectedSignature();

            return response()->json(['message' => 'Invalid Pixelforge signature.'], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}
