<?php

declare(strict_types=1);

use App\Models\User;

it('sends the baseline protections on every page', function () {
    $this->get(route('login'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

it('allows only our scripts, plus the inline theme script by its per-request nonce', function () {
    $response = $this->get(route('login'));
    $csp = $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([^']+)'/", (string) $csp, $nonce);

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain('img-src \'self\' data: blob: https://pixelforge.promptedit.dev')
        ->not->toContain('unsafe-eval')
        ->and($response->getContent())->toContain('<script nonce="'.$nonce[1].'">');
});

it('uses a fresh nonce for every request', function () {
    $first = $this->get(route('login'))->headers->get('Content-Security-Policy');
    $second = $this->get(route('login'))->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

it('can report violations without blocking, while a new host is being added', function () {
    config(['security.csp.report_only' => true]);

    $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');
});

it('protects JSON endpoints too', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.estimate'), [])
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
