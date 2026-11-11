<?php

namespace App\Providers;

use App\Http\Responses\ApiError;
use App\Listeners\AlertOnQueueBusy;
use App\Listeners\RecordPermissionChanges;
use App\Listeners\TrackDashboardLogins;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use App\Observers\PlatformAuditObserver;
use App\Policies\RolePolicy;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        $this->configureRateLimiting();
        $this->configurePasswords();
        $this->registerAuditTrail();
    }

    private function configureRateLimiting(): void
    {
        // Plan §7: per API key, in Redis (the default cache store).
        RateLimiter::for('audit-api', function (Request $request) {
            /** @var ProjectApiKey|null $key */
            $key = $request->attributes->get('api_key');

            return Limit::perMinute((int) config('audit.api.rate_limit_per_minute', 1000))
                ->by($key ? 'key:'.$key->id : 'ip:'.$request->ip())
                ->response(function (Request $request, array $headers) use ($key) {
                    app(SecurityEventRecorder::class)->record($request, 'rate_limited', $key?->project, $key);

                    return ApiError::make('rate_limited', 'Too many requests for this API key.', 429, null, $headers);
                });
        });

        RateLimiter::for('api-health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    private function configurePasswords(): void
    {
        // Plan §13: strong password policy for dashboard users.
        Password::defaults(fn () => Password::min((int) config('audit.accounts.password_min_length', 12))
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());
    }

    private function registerAuditTrail(): void
    {
        // Self-audit (plan §13).
        Project::observe(PlatformAuditObserver::class);
        ProjectApiKey::observe(PlatformAuditObserver::class);
        User::observe(PlatformAuditObserver::class);
        Role::observe(PlatformAuditObserver::class);

        Event::listen([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class], RecordPermissionChanges::class);
        Event::listen([Login::class, Logout::class, Failed::class], TrackDashboardLogins::class);

        Event::listen(QueueBusy::class, AlertOnQueueBusy::class);

        Event::listen(JobFailed::class, function (JobFailed $event) {
            Log::error('Queue job failed', ['job' => $event->job->resolveName(), 'error' => $event->exception->getMessage()]);
        });
    }
}
