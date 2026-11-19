<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /health/background is for operators only (ADR-0015): an uptime monitor sending
 * `Authorization: Bearer {HEALTH_CHECK_TOKEN}`. Without a configured token the endpoint does not
 * exist for anyone; admins see the same information on the System status page.
 */
final readonly class RequireHealthCheckToken
{
    public function __construct(
        #[Config('monitoring.health_token')] private ?string $token,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->bearerToken();

        if ($this->token === null || $this->token === '' || ! hash_equals($this->token, $given)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
