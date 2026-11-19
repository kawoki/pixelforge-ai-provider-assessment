<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security headers (ADR-0013)
|--------------------------------------------------------------------------
*/

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        // Report violations in the browser console without blocking (handy when adding a new host).
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
        // Generated images are shown straight from Pixelforge's signed URLs.
        'image_hosts' => ['https://pixelforge.promptedit.dev'],
        // Extra connect-src origins, comma-separated (e.g. a managed WebSocket host).
        'connect_extra' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_CONNECT_EXTRA', ''))))),
    ],

];
