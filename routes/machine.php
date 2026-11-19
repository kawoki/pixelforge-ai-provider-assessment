<?php

use App\Http\Controllers\BackgroundHealthController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\RequireHealthCheckToken;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine endpoints. Loaded outside the `web` group (bootstrap/app.php):
| no session, no cookies, no CSRF.
*/

// Authenticity comes from the signature (ADR-0007).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(['throttle:webhooks', VerifyPixelforgeSignature::class])
    ->name('webhooks.pixelforge');

// For an uptime monitor holding HEALTH_CHECK_TOKEN: 503 while the scheduler or queue worker is
// down (ADR-0015). 404 for everyone else; admins use the System status page.
Route::get('health/background', BackgroundHealthController::class)
    ->middleware(['throttle:60,1', RequireHealthCheckToken::class])
    ->name('health.background');
