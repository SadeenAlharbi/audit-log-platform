<?php

namespace App\Notifications;

use App\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational alert (plan §11): backup failure, queue backlog, repeated
 * rejected access, expiring keys...
 */
class PlatformAlert extends Notification
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $title,
        public string $message,
        public string $level = 'warning',
        public array $context = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->routeNotificationFor('mail') !== null) {
            $channels[] = 'mail';
        }
        if ($notifiable->routeNotificationFor(SlackWebhookChannel::class) !== null) {
            $channels[] = SlackWebhookChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] '.strtoupper($this->level).': '.$this->title)
            ->line($this->message);

        foreach ($this->context as $key => $value) {
            $mail->line("{$key}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        return $this->level === 'critical' ? $mail->error() : $mail;
    }

    /**
     * Slack incoming-webhook payload.
     *
     * @return array<string, mixed>
     */
    public function toSlackWebhook(object $notifiable): array
    {
        $emoji = match ($this->level) {
            'critical' => ':rotating_light:',
            'warning' => ':warning:',
            default => ':information_source:',
        };

        $fields = collect($this->context)
            ->map(fn ($v, $k) => "*{$k}:* ".(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v))
            ->implode("\n");

        return [
            'text' => "{$emoji} *".config('app.name').": {$this->title}*\n{$this->message}".($fields !== '' ? "\n{$fields}" : ''),
        ];
    }
}
