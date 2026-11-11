<?php

use App\Jobs\StoreAuditLogs;
use App\Models\IngestionBatch;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\PlatformAlert;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('marks the batch failed and alerts when storing fails for good', function () {
    Notification::fake();
    config(['audit.alerts.mail_to' => ['ops@example.com']]);
    $key = issueKey()->key;
    $batch = IngestionBatch::query()->create(['project_id' => $key->project_id, 'api_key_id' => $key->id, 'count' => 1, 'status' => 'queued']);

    (new StoreAuditLogs([], $batch->id))->failed(new RuntimeException('db down'));

    expect($batch->fresh()->status)->toBe('failed');
    Notification::assertSentOnDemand(PlatformAlert::class, fn ($n) => $n->level === 'critical');
});

it('posts alerts to a Slack webhook and throttles repeats', function () {
    Http::fake();
    config(['audit.alerts.slack_webhook_url' => 'https://hooks.slack.test/T000', 'audit.alerts.mail_to' => ['ops@example.com']]);

    expect(app(AlertNotifier::class)->send('k', 'Title', 'Body', 'critical', ['a' => 1]))->toBeTrue()
        ->and(app(AlertNotifier::class)->send('k', 'Title', 'Body'))->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/T000' && str_contains($request['text'], 'Title'));
});

it('renders the alert e-mail', function () {
    $mail = (new PlatformAlert('Disk', 'Almost full', 'critical', ['used' => '95%', 'ok' => false]))->toMail(new stdClass);

    expect($mail->subject)->toContain('CRITICAL: Disk')
        ->and(implode(' ', $mail->introLines))->toContain('used: 95%');
});

it('alerts when a queue is busy', function () {
    Notification::fake();
    config(['audit.alerts.mail_to' => ['ops@example.com']]);

    event(new QueueBusy('redis', 'ingest', 9000));

    Notification::assertSentOnDemand(PlatformAlert::class, fn ($n) => $n->title === 'Queue backlog');
});

it('skips the Slack channel without a webhook', function () {
    Http::fake();
    (new SlackWebhookChannel)->send(Notification::route('mail', 'x@example.com'), new PlatformAlert('a', 'b'));

    Http::assertNothingSent();
});

it('requests a restore test from the backup container', function () {
    config(['audit.backup.spool_path' => storage_path('framework/testing/spool-rt')]);

    $this->artisan('backup:restore-test')->assertSuccessful();

    expect(glob(storage_path('framework/testing/spool-rt/requests/restore-test-*.req')))->toHaveCount(1);
    File::deleteDirectory(storage_path('framework/testing/spool-rt'));
});

it('requests database backups through backup:run', function () {
    config(['audit.backup.spool_path' => storage_path('framework/testing/spool-run')]);

    $this->artisan('backup:run --type=full')->assertSuccessful();
    $this->artisan('backup:run --type=nope')->assertFailed();

    expect(glob(storage_path('framework/testing/spool-run/requests/full-*.req')))->toHaveCount(1);
    File::deleteDirectory(storage_path('framework/testing/spool-run'));
});

it('does not expose a $batchId on the storage job (it would be read as a Bus batch id)', function () {
    // Regression: with Telescope or Horizon watching jobs, a public
    // $batchId made every batch ingestion look up the job_batches table.
    $job = new StoreAuditLogs([], 42);

    expect(property_exists($job, 'batchId'))->toBeFalse()
        ->and($job->ingestionBatchId)->toBe(42);
});
