<?php

declare(strict_types=1);

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Regression: a Postgres server in a non-UTC zone (e.g. a laptop in Asia/Manila) used to shift
// every timestamp, which would put generations on the wrong billing day vs GET /v1/usage.

it('talks to Postgres in UTC whatever the server default is', function () {
    expect(DB::selectOne('SHOW TIME ZONE')->TimeZone)->toBe('UTC');
});

it('stores Pixelforge\'s completion time as the same instant', function () {
    $generation = Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse('2026-11-04T23:30:00Z')]);

    $stored = DB::selectOne("SELECT to_char(completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS utc FROM generations WHERE id = ?", [$generation->id]);

    expect($stored->utc)->toBe('2026-11-04 23:30:00')
        ->and($generation->fresh()->completed_at->toIso8601String())->toBe('2026-11-04T23:30:00+00:00');
});
