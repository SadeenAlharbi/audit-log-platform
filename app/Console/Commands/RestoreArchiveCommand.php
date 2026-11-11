<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\Alerts\AlertNotifier;
use App\Services\Archive\ArchiveRestorer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('audit:restore-archive
    {project : Project slug}
    {--from= : occurred_at from (date)}
    {--to= : occurred_at to (date, inclusive)}
    {--dry-run : Count only}
    {--user= : Id of the dashboard user who requested it}')]
#[Description('Restore one project\'s audit logs from the JSONL archive (partial restore)')]
class RestoreArchiveCommand extends Command
{
    public function handle(ArchiveRestorer $restorer, AlertNotifier $alerts): int
    {
        $project = Project::query()->withoutGlobalScopes()->where('slug', $this->argument('project'))->first();
        if ($project === null) {
            $this->components->error('Unknown project.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::parse($this->option('from') ?? '1970-01-01', 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->option('to') ?? 'now', 'UTC')->endOfDay();
        $user = $this->option('user') ? User::query()->find($this->option('user')) : null;

        try {
            $result = $restorer->restore($project, $from, $to, $user, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $alerts->send('restore:failed:'.$project->slug, 'Archive restore failed', $e->getMessage(), 'critical', ['project' => $project->slug]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Files: {$result['files']}, rows in range: {$result['rows']}, inserted: {$result['inserted']}.");

        return self::SUCCESS;
    }
}
