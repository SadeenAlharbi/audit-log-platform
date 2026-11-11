<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\PlatformAuditLogger;
use App\Services\Security\AccessReview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

#[Signature('access:review {--sensitive-only : Only users holding a sensitive permission} {--no-mail}')]
#[Description('Export who has which role and permission (quarterly access review) and send it to Super Admins')]
class AccessReviewCommand extends Command
{
    public function handle(AccessReview $review, PlatformAuditLogger $audit): int
    {
        $sensitiveOnly = (bool) $this->option('sensitive-only');
        $csv = $review->csv($sensitiveOnly);
        $path = 'access-reviews/access-review-'.now()->utc()->format('Ymd-His').($sensitiveOnly ? '-sensitive' : '').'.csv';
        Storage::disk('local')->put($path, $csv);

        $recipients = User::query()->active()->role('super_admin')->pluck('email')->all();

        if (! $this->option('no-mail') && $recipients !== []) {
            Mail::raw(
                ($sensitiveOnly ? 'Users holding sensitive permissions' : 'Quarterly access review').' is attached. Review every line and remove access that is no longer needed.',
                fn ($message) => $message->to($recipients)
                    ->subject('['.config('app.name').'] '.($sensitiveOnly ? 'Sensitive permissions report' : 'Access review'))
                    ->attachData($csv, basename($path), ['mime' => 'text/csv']),
            );
        }

        $audit->record('access.review_generated', 'users', ['file' => $path, 'sensitive_only' => $sensitiveOnly, 'mailed_to' => count($recipients)]);
        $this->components->info("Access review written to storage/app/private/{$path}.");

        return self::SUCCESS;
    }
}
