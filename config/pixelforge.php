<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pixelforge
|--------------------------------------------------------------------------
|
| Source of truth for the API: PIXELFORGE_API.md. One API key (and webhook
| secret) per environment. Money values are strings on purpose: they are
| parsed into exact decimals (ADR-0003). Never write them as floats.
|
*/

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'http' => [
        'timeout_seconds' => 15,
        'connect_timeout_seconds' => 5,
    ],

    'webhook' => [
        'secret' => env('PIXELFORGE_WEBHOOK_SECRET'), // whsec_…, per API key
        'tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    // What users pay on top of Pixelforge's cost (25%). Snapshotted on each generation.
    'markup' => '0.25',

    // Pixelforge responses that don't change are cached, to save the shared 60 requests/min.
    'cache' => [
        'pricing_seconds' => 3600,
        // Usage for days that are over never changes; today's grows as generations complete.
        'usage_past_seconds' => 3600,
        'usage_today_seconds' => 300,
    ],

    'pricing' => [

        // Used only for estimates when /v1/pricing is unreachable. Same shape as the
        // endpoint (copied from PIXELFORGE_API.md, 2026-11-19). Rates: USD per 1M tokens.
        'fallback' => [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => '0.15',
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ],
    ],

    // Accepted request values (POST /v1/generations). Unknown params are rejected with 422.
    'options' => [
        'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
        'qualities' => ['low', 'medium', 'high'],
        'default_size' => '1024x1024',
        'default_quality' => 'medium',
        'max_reference_images' => 4,
        'prompt_max_length' => 4000,
    ],

    // Fairness: Pixelforge's limits are per account and shared by all our users, so no single
    // user may hold every slot or use up the daily quota.
    'per_user' => [
        'max_running' => 3,
        'max_per_day' => 100,
    ],

    // Account limits (shared by all keys): 60 req/min, 3 concurrent generations, 500/day UTC.
    'submission' => [
        // Waiting for a free slot (3 at a time) is retried this long.
        'retry_until_minutes' => 30,
        'backoff_seconds' => [2, 5, 15, 30, 60],
        // "Overloaded"/rate-limited refusals: give up after this many (about a minute), as the docs advise.
        'max_attempts_when_unavailable' => 5,
    ],

    // Degraded mode (ADR-0015): when the queue or scheduler is down, the History page's polling
    // asks the server to check Pixelforge directly for that user's in-flight generations.
    'fallback' => [
        'max_per_request' => 3,
        // Each generation is checked at most this often, however many tabs poll.
        'per_generation_seconds' => 15,
        // Shared budget across all users (Pixelforge allows 60 requests/min per account).
        'max_requests_per_minute' => 20,
    ],

    // Polling fallback for lost webhooks (ADR-0010). Each poll is one request against the
    // shared 60/min account limit, so batches stay small; a generation is re-polled at most
    // every `stale_after_seconds` because each poll counts as provider contact.
    'reconciliation' => [
        'stale_after_seconds' => 120,
        'batch_size' => 10,
        // A pending generation this old has outlived its submission job (30 min window): requeue it.
        'lost_submission_after_minutes' => 40,
        // Pixelforge answering not_found this many polls in a row (~10 min): fail it, free, and alert.
        'give_up_after_not_found' => 5,
    ],

];
