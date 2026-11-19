<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Credits\AdjustCreditsAction;
use App\Models\User;
use App\Support\Money\Usd;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('credits:adjust {email : The user\'s email address} {amount : Dollars to add, or negative to remove, e.g. 0.0611 or -1.50} {--reason= : Why (required, stored on the ledger entry)} {--generation= : The generation this relates to, if any} {--force : Skip the confirmation}')]
#[Description('Refund or correct a user\'s credits with a ledger adjustment')]
final class AdjustCreditsCommand extends Command
{
    public function handle(AdjustCreditsAction $adjust): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null || ! $this->confirmed($user)) {
            $this->error($user === null ? 'No user with that email address.' : 'Cancelled.');

            return self::FAILURE;
        }

        return $this->adjust($adjust, $user);
    }

    private function adjust(AdjustCreditsAction $adjust, User $user): int
    {
        try {
            $entry = $adjust->execute($user, Usd::of((string) $this->argument('amount')), (string) $this->option('reason'), $this->option('generation'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Adjusted {$user->email} by {$entry->amount->format(4)}. Balance is now {$entry->balance_after->format(4)}.");

        return self::SUCCESS;
    }

    private function confirmed(User $user): bool
    {
        return $this->option('force') || $this->confirm("Adjust {$user->email} ({$user->balance->format(4)}) by {$this->argument('amount')}?");
    }
}
