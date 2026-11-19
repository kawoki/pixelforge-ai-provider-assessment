<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A user's own generations, newest first. Always scoped to the user (ADR-0013).
 */
final readonly class ListUserGenerationsAction
{
    public const int PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, Generation>
     */
    public function execute(User $user): LengthAwarePaginator
    {
        return Generation::query()
            ->whereBelongsTo($user)
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }
}
