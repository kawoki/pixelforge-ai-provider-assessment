<?php

namespace App\Providers;

use App\Contracts\PixelforgeClient;
use App\Enums\AlertType;
use App\Models\User;
use App\Services\Alerts\AdminAlertService;
use App\Services\Pixelforge\CachedPixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, CachedPixelforgeClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // `composer dev` also runs the scheduler (ADR-0010) and the WebSocket server (ADR-0009).
        // --debug prints connections, subscriptions and messages; Reverb is silent without it.
        DevCommands::artisan('schedule:work', 'scheduler');
        DevCommands::artisan('reverb:start --debug', 'reverb');

        $this->alertOnFailedJobs();

        // Operators' pages (ADR-0012). is_admin is granted with `php artisan users:admin {email}`.
        Gate::define('viewAdminReports', fn (User $user): bool => $user->is_admin);
    }

    /**
     * A job that gave up for good is a generation not submitted, or a webhook not applied (ADR-0015).
     */
    protected function alertOnFailedJobs(): void
    {
        Queue::failing(fn (JobFailed $event) => app(AdminAlertService::class)->raise(
            AlertType::JobFailed,
            "{$event->job->resolveName()} failed: {$event->exception->getMessage()}",
        ));
    }

    /**
     * Per-user limits on our own endpoints (ADR-0013). Pixelforge's account limits are
     * handled separately, by the submission job's retries (ADR-0005).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('generate', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('estimate', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('purchase', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        // Fallback polling every ~5 s while background processing is down (ADR-0015).
        RateLimiter::for('catch-up', fn (Request $request): Limit => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        // Generous: rejecting a genuine webhook only makes Pixelforge retry it later.
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
