<?php

namespace App\Console\Commands;

use App\Services\Security\SecurityEventRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan §7 "every failed attempt is recorded" (decision D21): writes the
 * attempts that were counted instead of stored one by one (over the
 * per-minute sample) as one summary row per IP, reason and minute.
 */
#[Signature('audit:flush-security-events')]
#[Description('Write the counted (sampled-out) rejected API requests to security_events')]
class FlushSecurityEventsCommand extends Command
{
    public function handle(SecurityEventRecorder $recorder): int
    {
        $count = $recorder->flushSuppressed();
        $this->components->info("{$count} counted attempt(s) written.");

        return self::SUCCESS;
    }
}
