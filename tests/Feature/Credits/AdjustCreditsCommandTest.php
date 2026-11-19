<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;

beforeEach(fn () => $this->user = User::factory()->withCredits('5.00')->create(['email' => 'jane@example.test']));

it('refunds credits with a reason kept on the ledger', function () {
    $this->artisan('credits:adjust', ['email' => 'jane@example.test', 'amount' => '0.0611', '--reason' => 'Pixelforge ran an abandoned generation', '--force' => true])
        ->expectsOutputToContain('Balance is now $5.0611')
        ->assertSuccessful();

    expect(CreditTransaction::query()->where('type', CreditTransactionType::Adjustment)->sole())
        ->amount->toDecimalString()->toBe('0.061100000000')
        ->meta->toBe(['reason' => 'Pixelforge ran an abandoned generation']);
});

it('takes credits back with a negative amount', function () {
    $this->artisan('credits:adjust', ['email' => 'jane@example.test', 'amount' => '-1.50', '--reason' => 'Duplicate purchase', '--force' => true])->assertSuccessful();

    expect($this->user->fresh()->balance->toDecimalString())->toBe('3.500000000000');
});

it('refuses adjustments without a reason, a zero amount, or an unknown user', function (array $arguments) {
    $this->artisan('credits:adjust', [...$arguments, '--force' => true])->assertFailed();

    expect(CreditTransaction::query()->where('type', CreditTransactionType::Adjustment)->count())->toBe(0);
})->with([
    'no reason' => [['email' => 'jane@example.test', 'amount' => '1']],
    'zero' => [['email' => 'jane@example.test', 'amount' => '0', '--reason' => 'x']],
    'not a number' => [['email' => 'jane@example.test', 'amount' => 'lots', '--reason' => 'x']],
    'unknown user' => [['email' => 'nobody@example.test', 'amount' => '1', '--reason' => 'x']],
]);

it('asks for confirmation unless forced', function () {
    $this->artisan('credits:adjust', ['email' => 'jane@example.test', 'amount' => '1', '--reason' => 'x'])
        ->expectsConfirmation('Adjust jane@example.test ($5.0000) by 1?', 'no')
        ->assertFailed();
});
