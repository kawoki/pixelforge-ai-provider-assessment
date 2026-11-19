<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Exceptions\Generations\GenerationLimitReachedException;
use App\Models\User;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fair use of Pixelforge's shared account limits (3 running, 500 per UTC day, for all users
 * together): no single user may hold every slot or use up the day's quota.
 */
final readonly class GenerationQuotaService
{
    /**
     * @param  array{max_running: int, max_per_day: int}  $limits
     */
    public function __construct(
        #[Config('pixelforge.per_user')] private array $limits,
    ) {}

    /**
     * Why this user can't start another generation right now, or null if they can.
     */
    public function blockReason(User $user): ?string
    {
        $running = $user->generations()->whereIn('status', GenerationStatus::inFlight())->count();

        if ($running >= $this->limits['max_running']) {
            return "You already have {$running} generations in progress. Wait for one to finish: up to {$this->limits['max_running']} can run at a time.";
        }

        return $this->startedToday($user) >= $this->limits['max_per_day']
            ? "You've reached today's limit of {$this->limits['max_per_day']} generations. It resets at midnight UTC."
            : null;
    }

    /**
     * Call with a row-locked user (see CreateGenerationAction), so parallel submissions can't slip past.
     *
     * @throws GenerationLimitReachedException
     */
    public function assertCanStart(User $lockedUser): void
    {
        $reason = $this->blockReason($lockedUser);

        if ($reason !== null) {
            throw new GenerationLimitReachedException($reason);
        }
    }

    /**
     * Rejected submissions never reached Pixelforge's quota, so they don't count.
     */
    private function startedToday(User $user): int
    {
        return $user->generations()
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->where(fn (Builder $query) => $query->whereNotNull('provider_generation_id')->orWhere('status', '!=', GenerationStatus::Failed->value))
            ->count();
    }
}
