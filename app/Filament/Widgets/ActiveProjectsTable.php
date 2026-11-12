<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectApiKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ActiveProjectsTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'المشاريع الأنشط (24 ساعة)';

    public function table(Table $table): Table
    {
        $since = now()->utc()->subDay();

        return $table
            ->query(fn (): Builder => Project::query()
                ->whereNull('archived_at')
                ->select('projects.*')
                ->selectSub(DB::table('audit_logs')->selectRaw('COUNT(*)')
                    ->whereColumn('audit_logs.project_id', 'projects.id')
                    ->where('audit_logs.occurred_at', '>=', $since), 'logs_24h')
                ->selectSub(DB::table('project_api_keys')->selectRaw('MAX(last_used_at)')
                    ->whereColumn('project_api_keys.project_id', 'projects.id')
                    ->whereJsonContains('scopes', ProjectApiKey::SCOPE_WRITE), 'last_received_at')
                ->orderByDesc('logs_24h')
                ->limit(10))
            ->paginated(false)
            ->recordUrl(fn (Project $record) => ProjectResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('المشروع'),
                TextColumn::make('logs_24h')->label('سجلات 24 ساعة')->numeric(),
                TextColumn::make('last_received_at')->label('آخر استلام')->since()->placeholder('—'),
            ]);
    }
}
