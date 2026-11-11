<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Posts to a Slack incoming webhook. No extra package needed.
 */
class SlackWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor(self::class, $notification);

        if (! is_string($url) || $url === '' || ! method_exists($notification, 'toSlackWebhook')) {
            return;
        }

        Http::timeout(5)->retry(2, 500, throw: false)->post($url, $notification->toSlackWebhook($notifiable));
    }
}
