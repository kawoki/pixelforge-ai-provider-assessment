<?php

declare(strict_types=1);

use App\Enums\GenerationStatus as S;

it('only allows forward transitions out of non-terminal states', function (S $from, S $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'pending → queued' => [S::Pending, S::Queued, true],
    'pending → succeeded (skips states)' => [S::Pending, S::Succeeded, true],
    'queued → processing' => [S::Queued, S::Processing, true],
    'queued → failed' => [S::Queued, S::Failed, true],
    'processing → succeeded' => [S::Processing, S::Succeeded, true],
    'processing → queued (out of order)' => [S::Processing, S::Queued, false],
    'processing → processing (duplicate)' => [S::Processing, S::Processing, false],
    'succeeded → processing (late event)' => [S::Succeeded, S::Processing, false],
    'succeeded → failed (conflicting terminal)' => [S::Succeeded, S::Failed, false],
    'failed → succeeded (conflicting terminal)' => [S::Failed, S::Succeeded, false],
    'succeeded → succeeded (duplicate)' => [S::Succeeded, S::Succeeded, false],
]);

it('knows which states are terminal', function () {
    expect(S::Succeeded->isTerminal())->toBeTrue()
        ->and(S::Failed->isTerminal())->toBeTrue()
        ->and(S::inFlight())->toBe([S::Pending, S::Queued, S::Processing]);
});

it('shows pending to users as queued', function () {
    expect(S::Pending->label())->toBe('Queued')
        ->and(S::Processing->label())->toBe('Processing');
});
