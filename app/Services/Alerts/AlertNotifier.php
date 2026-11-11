<?php

namespace App\Services\Alerts;

use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\PlatformAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends operational alerts by e-mail and/or Slack, at most once per
 * throttle window for the same alert key.
 */
class AlertNotifier
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function send(string $key, string $title, string $message, string $level = 'warning', array $context = []): bool
    {
        $ttl = now()->addMinutes((int) config('audit.alerts.throttle_minutes', 30));

        if (! Cache::add('alerts:sent:'.sha1($key), true, $ttl)) {
            return false;
        }

        Log::log($level === 'critical' ? 'critical' : 'warning', "[alert] {$title}: {$message}", $context);

        $mailTo = config('audit.alerts.mail_to', []);
        $slack = config('audit.alerts.slack_webhook_url');

        if ($mailTo === [] && blank($slack)) {
            return true;
        }

        $route = Notification::route('mail', $mailTo === [] ? null : $mailTo);
        if (! blank($slack)) {
            $route->route(SlackWebhookChannel::class, $slack);
        }

        try {
            $route->notify(new PlatformAlert($title, $message, $level, $context));
        } catch (Throwable $e) {
            // An alert must never break the operation that raised it.
            Log::error('Failed to deliver alert', ['alert' => $title, 'error' => $e->getMessage()]);
        }

        return true;
    }
}
