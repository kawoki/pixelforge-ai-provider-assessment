<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Delete account" from settings. Generations and ledger entries are financial records that
 * the spend report and Pixelforge reconciliation depend on, so a user who has any is
 * anonymised instead: everything that identifies them, or lets anyone sign in, is removed.
 * Users without money history are deleted outright.
 */
final readonly class DeleteAccountAction
{
    public function execute(User $user): void
    {
        $this->hasMoneyHistory($user) ? $this->anonymise($user) : $user->delete();
    }

    private function hasMoneyHistory(User $user): bool
    {
        return $user->generations()->exists() || $user->creditTransactions()->exists();
    }

    private function anonymise(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->passkeys()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill($this->scrubbed($user))->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function scrubbed(User $user): array
    {
        return [
            'name' => 'Deleted user',
            'email' => "deleted-{$user->id}@users.invalid",
            'password' => Str::random(64), // hashed by the model; nobody knows it
            'email_verified_at' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_admin' => false,
            'anonymized_at' => now(),
        ];
    }
}
