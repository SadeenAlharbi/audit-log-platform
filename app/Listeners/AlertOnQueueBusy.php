<?php

namespace App\Listeners;

use App\Services\Alerts\AlertNotifier;
use Illuminate\Queue\Events\QueueBusy;

class AlertOnQueueBusy
{
    public function __construct(private AlertNotifier $alerts) {}

    public function handle(QueueBusy $event): void
    {
        $this->alerts->send(
            key: "queue:busy:{$event->connectionName}:{$event->queue}",
            title: 'Queue backlog',
            message: "Queue {$event->connectionName}:{$event->queue} has {$event->size} pending jobs. Check that Horizon/queue workers are running.",
            level: 'critical',
            context: ['queue' => $event->queue, 'size' => $event->size],
        );
    }
}
