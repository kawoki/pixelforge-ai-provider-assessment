<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections for every page (ADR-0013): CSP with a per-request script
 * nonce, no framing, no MIME sniffing, a strict referrer policy, no powerful browser
 * features, and HSTS over HTTPS.
 */
final readonly class AddSecurityHeaders
{
    /**
     * @param  array{enabled: bool, report_only: bool}  $csp
     */
    public function __construct(
        private ContentSecurityPolicy $policy,
        #[Config('security.csp')] private array $csp,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce(); // also stamps the nonce on every tag @vite emits

        $response = $next($request);
        $this->baseline($response, $request);

        if ($this->csp['enabled']) {
            $name = $this->csp['report_only'] ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($name, $this->policy->header($nonce));
        }

        return $response;
    }

    private function baseline(Response $response, Request $request): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
