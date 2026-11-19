<?php

declare(strict_types=1);

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function deleteAccount(User $user): TestResponse
{
    return test()->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);
}

it('anonymises a user with money history instead of crashing', function () {
    $user = User::factory()->withCredits('10.00')->create(['name' => 'Jane Doe', 'email' => 'jane@example.test']);
    Generation::factory()->for($user)->succeeded()->create();

    deleteAccount($user)->assertRedirect('/');

    expect($user->fresh())
        ->name->toBe('Deleted user')
        ->email->toBe("deleted-{$user->id}@users.invalid")
        ->anonymized_at->not->toBeNull()
        ->is_admin->toBeFalse()
        ->and(Generation::count())->toBe(1)
        ->and(CreditTransaction::count())->toBe(1);
    $this->assertGuest();
});

it('leaves no way back in: old password, sessions and passkeys are gone', function () {
    $user = User::factory()->withCredits()->create(['email' => 'jane@example.test']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

    deleteAccount($user);

    $this->post(route('login.store'), ['email' => 'jane@example.test', 'password' => 'password'])->assertSessionHasErrors();
    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->passkeys()->count())->toBe(0);
});

it('deletes a user with no money history outright', function () {
    $user = User::factory()->create();

    deleteAccount($user)->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});
