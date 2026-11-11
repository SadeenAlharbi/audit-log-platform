<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon (plan §2, §11) at /horizon - Super Admin only, in every
 * environment (the default would open it to anyone locally).
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(LongWaitDetected::class, function (LongWaitDetected $event) {
            app(AlertNotifier::class)->send(
                key: "horizon:wait:{$event->connection}:{$event->queue}",
                title: 'Queue wait time is too long',
                message: "Jobs on {$event->connection}:{$event->queue} wait {$event->seconds} seconds before being processed.",
                level: 'critical',
            );
        });
    }

    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null && $user->canGlobally('settings.manage'));
    }
}
