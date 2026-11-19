<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\CreditPackData;
use App\Models\User;
use App\Services\Credits\CreditLedgerService;
use App\Support\Money\Usd;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Accounts for reviewers: a demo user with credits, and an operator who can see the spend report
 * and System status. Safe to run again: accounts are updated, credits are granted only once.
 *
 * Passwords come from DEMO_USER_PASSWORD / DEMO_ADMIN_PASSWORD. Without them, local and testing
 * use "password" and other environments get a random one, printed once.
 */
final class DemoSeeder extends Seeder
{
    public function __construct(private readonly CreditLedgerService $ledger) {}

    public function run(): void
    {
        $this->account('Demo User', 'demo@example.com', 'demo.user_password', admin: false, credits: '10.00');
        $this->account('Operator', 'admin@example.com', 'demo.admin_password', admin: true, credits: '5.00');
    }

    private function account(string $name, string $email, string $passwordConfig, bool $admin, string $credits): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $password = $this->password($passwordConfig, $email);

        $user->forceFill(['name' => $name, 'password' => $password, 'email_verified_at' => now(), 'is_admin' => $admin])->save();
        $this->ledger->purchase($user, new CreditPackData('demo', 'Demo credits', Usd::of($credits)), "demo-seed-{$user->id}");
    }

    private function password(string $configKey, string $email): string
    {
        $configured = config($configKey);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $password = app()->environment('local', 'testing') ? 'password' : Str::password(20);
        $this->command?->info("{$email} password: {$password}");

        return $password;
    }
}
