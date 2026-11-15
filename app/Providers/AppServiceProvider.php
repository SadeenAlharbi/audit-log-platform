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
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // One digit style across the dashboard (Latin digits, as most Saudi
        // government platforms use): Filament formats numbers with the app
        // locale (ar) unless told otherwise, which mixed ٣٦٥ and 365.
        Table::configureUsing(fn (Table $table) => $table->defaultNumberLocale('en'));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultNumberLocale('en'));

        Gate::policy(Role::class, RolePolicy::class);

        // Security review M2/L3: in production every generated URL (reset
        // links, signed URLs) uses APP_URL over HTTPS, whatever the request.
        if ($this->app->environment('production')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();
        $this->configurePasswords();
        $this->registerAuditTrail();
        $this->runSchemaCommandsOnMaintenanceConnection();
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

    /**
     * Security review H1: the runtime database account cannot ALTER, DROP or
     * manage triggers. Schema commands (migrate, db:wipe, schema:dump)
     * therefore run on the "maintenance" connection, so `php artisan migrate`
     * keeps working without granting those privileges to the app.
     */
    private function runSchemaCommandsOnMaintenanceConnection(): void
    {
        $commands = ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
            'migrate:install', 'migrate:status', 'db:wipe', 'schema:dump'];
        $previous = null;

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($commands, &$previous) {
            $maintenance = (string) config('audit.maintenance_connection', 'maintenance');
            if (in_array($event->command, $commands, true) && $event->input->getParameterOption('--database') === false
                && DB::getDefaultConnection() !== $maintenance) {
                $previous = DB::getDefaultConnection();
                DB::setDefaultConnection($maintenance);
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($commands, &$previous) {
            if ($previous !== null && in_array($event->command, $commands, true)) {
                DB::setDefaultConnection($previous);
                $previous = null;
            }
        });
    }
}
