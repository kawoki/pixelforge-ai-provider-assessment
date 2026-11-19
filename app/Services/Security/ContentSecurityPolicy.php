<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Container\Attributes\Config;
use Illuminate\Foundation\Vite;

/**
 * Builds the Content-Security-Policy (ADR-0013).
 *
 * Scripts: our own plus the one inline theme script, by nonce. Styles allow 'unsafe-inline'
 * because Vue `:style` bindings set style attributes; adding a nonce there would disable it.
 * Images: ours plus Pixelforge's image host. Connections: ours plus the Reverb WebSocket.
 * While the Vite dev server runs (local only), its origins are allowed too.
 */
final readonly class ContentSecurityPolicy
{
    /**
     * @param  array{image_hosts: list<string>, connect_extra: list<string>}  $csp
     * @param  array{host?: string|null, port?: int|string|null, scheme?: string|null}  $reverb
     */
    public function __construct(
        private Vite $vite,
        #[Config('security.csp')] private array $csp,
        #[Config('broadcasting.connections.reverb.options')] private array $reverb = [],
    ) {}

    public function header(string $nonce): string
    {
        $dev = $this->devOrigins();

        return implode('; ', [
            "default-src 'self'",
            $this->directive('script-src', ["'self'", "'nonce-{$nonce}'", ...$dev['http']]),
            $this->directive('style-src', ["'self'", "'unsafe-inline'", ...$dev['http']]),
            $this->directive('img-src', ["'self'", 'data:', 'blob:', ...$this->csp['image_hosts']]),
            $this->directive('font-src', ["'self'", 'data:', ...$dev['http']]),
            $this->directive('connect-src', ["'self'", ...$this->reverbOrigins(), ...$this->csp['connect_extra'], ...$dev['http'], ...$dev['ws']]),
            "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
        ]);
    }

    /**
     * @param  list<string>  $sources
     */
    private function directive(string $name, array $sources): string
    {
        return $name.' '.implode(' ', array_unique($sources));
    }

    /**
     * @return list<string>
     */
    private function reverbOrigins(): array
    {
        $host = $this->reverb['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return [];
        }

        $secure = ($this->reverb['scheme'] ?? 'https') === 'https';
        $port = $this->reverb['port'] ?? ($secure ? 443 : 80);

        return [($secure ? 'wss' : 'ws')."://{$host}:{$port}"];
    }

    /**
     * The Vite dev server is reached as localhost, 127.0.0.1 and [::1] depending on the browser.
     *
     * @return array{http: list<string>, ws: list<string>}
     */
    private function devOrigins(): array
    {
        if (! app()->isLocal() || ! $this->vite->isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $port = parse_url(trim((string) file_get_contents($this->vite->hotFile())), PHP_URL_PORT) ?: 5173;
        $hosts = ['localhost', '127.0.0.1', '[::1]'];

        return [
            'http' => array_map(fn (string $host): string => "http://{$host}:{$port}", $hosts),
            'ws' => array_map(fn (string $host): string => "ws://{$host}:{$port}", $hosts),
        ];
    }
}
