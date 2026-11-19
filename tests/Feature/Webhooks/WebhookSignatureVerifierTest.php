<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\PixelforgeNotConfiguredException;
use App\Exceptions\Webhooks\InvalidWebhookSignatureException;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Tests\Support\PixelforgeFixtures;

const VERIFIER_SECRET = 'whsec_test_secret';

function verifier(?string $secret = VERIFIER_SECRET): WebhookSignatureVerifier
{
    return new WebhookSignatureVerifier($secret, toleranceSeconds: 300);
}

beforeEach(fn () => $this->freezeTime());

it('accepts a correctly signed, fresh body', function () {
    $body = '{"id":"evt_1","type":"ping"}';

    verifier()->verify($body, PixelforgeFixtures::signature($body, VERIFIER_SECRET));

    expect(true)->toBeTrue();
});

it('accepts any matching v1 signature (secret rotation)', function () {
    $body = '{"id":"evt_1"}';
    $t = time();
    $valid = PixelforgeFixtures::signature($body, VERIFIER_SECRET, $t);

    verifier()->verify($body, "t={$t},v1=deadbeef,".explode(',', $valid)[1]);

    expect(true)->toBeTrue();
});

it('rejects anything not signed by Pixelforge, or too old to trust', function (Closure $header) {
    $body = '{"id":"evt_1","type":"generation.succeeded"}';

    verifier()->verify($body, $header($body));
})->throws(InvalidWebhookSignatureException::class)->with([
    'wrong secret' => [fn (string $body) => PixelforgeFixtures::signature($body, 'whsec_other')],
    'tampered body' => [fn (string $body) => PixelforgeFixtures::signature($body.' ', VERIFIER_SECRET)],
    'replayed (6 min old)' => [fn (string $body) => PixelforgeFixtures::signature($body, VERIFIER_SECRET, time() - 360)],
    'from the future' => [fn (string $body) => PixelforgeFixtures::signature($body, VERIFIER_SECRET, time() + 360)],
    'missing header' => [fn () => null],
    'no timestamp' => [fn (string $body) => 'v1='.hash_hmac('sha256', $body, VERIFIER_SECRET)],
    'no signature' => [fn () => 't='.time()],
    'garbage' => [fn () => 'nonsense'],
]);

it('allows the full documented tolerance', function () {
    $body = '{"id":"evt_1"}';

    verifier()->verify($body, PixelforgeFixtures::signature($body, VERIFIER_SECRET, time() - 300));

    expect(true)->toBeTrue();
});

it('fails closed when no secret is configured', function () {
    verifier(secret: null)->verify('{}', PixelforgeFixtures::signature('{}', VERIFIER_SECRET));
})->throws(PixelforgeNotConfiguredException::class, 'PIXELFORGE_WEBHOOK_SECRET');
