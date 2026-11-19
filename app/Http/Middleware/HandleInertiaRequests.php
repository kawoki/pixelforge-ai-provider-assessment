<?php

namespace App\Http\Middleware;

use App\Actions\Health\CheckBackgroundHealthAction;
use App\Http\Resources\BalanceSummaryResource;
use App\Services\Credits\BalanceService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Lets pages switch to fallback polling and warn users when background processing is down (ADR-0015).
            'background' => fn (): ?array => $request->user() === null ? null : $this->background(),
            // Balance, reserved and available credits, shown across the app (ADR-0011).
            'credits' => fn (): ?BalanceSummaryResource => $request->user() === null ? null : new BalanceSummaryResource(app(BalanceService::class)->summary($request->user())),
        ];
    }

    /**
     * @return array{degraded: bool}
     */
    private function background(): array
    {
        return ['degraded' => app(CheckBackgroundHealthAction::class)->execute()->degraded()];
    }
}
