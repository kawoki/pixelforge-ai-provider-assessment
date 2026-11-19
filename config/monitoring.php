<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Background processing health and admin alerts (ADR-0015)
|--------------------------------------------------------------------------
*/

return [

    'health' => [
        // A heartbeat older than this means the process has stopped.
        'stale_after_seconds' => 180,
        // A job waiting longer than this means no worker is taking jobs.
        'max_job_wait_seconds' => 180,
        // Right after a (re)deploy no heartbeat exists yet; don't call that an outage.
        'startup_grace_seconds' => 180,
        // Health is read on every page; cache the verdict briefly.
        'cache_seconds' => 10,
    ],

    // How long records are kept before the daily prune.
    'retention' => [
        'webhook_events_days' => 60,
        'alert_log_days' => 180,
    ],

    // Optional bearer token for an external uptime monitor on GET /health/background.
    // Unset: the endpoint is closed to everyone, and admins use the System status page.
    'health_token' => env('HEALTH_CHECK_TOKEN'),

    'alerts' => [
        // Admin users (users.is_admin) always get alerts; these addresses too (comma-separated).
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALERT_EMAILS', ''))))),
        // While a problem lasts, remind at most this often.
        'repeat_after_minutes' => 60,
        // Counting window for the delivery problems below.
        'window_minutes' => 60,
        // Rejected webhook signatures before we alert (wrong PIXELFORGE_WEBHOOK_SECRET?).
        'rejected_webhooks_threshold' => 5,
        // Generations finished by polling with no webhook ever received (wrong webhook URL?).
        'missed_webhooks_threshold' => 3,
    ],

];
