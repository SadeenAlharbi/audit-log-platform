<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Projects\Actions\ArchiveProjectAction;
use App\Filament\Resources\Projects\Actions\SendTestLogAction;
use App\Models\ProjectApiKey;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['apiKeys as active_keys_count' => fn (Builder $q) => $q->withoutGlobalScopes()
                    ->whereNull('revoked_at')
                    ->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
                ->addSelect([
                    'logs_today' => DB::table('audit_logs')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('audit_logs.project_id', 'projects.id')
                        ->where('audit_logs.occurred_at', '>=', now()->utc()->startOfDay()),
                    // Last time a write key was used (plan §8 "last received"),
                    // i.e. by arrival time, not by the event's own occurred_at.
                    'last_received_at' => DB::table('project_api_keys')
                        ->selectRaw('MAX(last_used_at)')
                        ->whereColumn('project_api_keys.project_id', 'projects.id')
                        ->whereJsonContains('scopes', ProjectApiKey::SCOPE_WRITE),
                ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable()->weight('medium'),
                TextColumn::make('slug')->label('المعرّف')->searchable()->fontFamily('mono')->copyable(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('logs_today')->label('سجلات اليوم')->numeric()->sortable(),
                TextColumn::make('last_received_at')->label('آخر استلام')->dateTime()->since()->placeholder('—')->sortable(),
                TextColumn::make('active_keys_count')->label('مفاتيح نشطة')->numeric(),
                TextColumn::make('retention_days')->label('الاحتفاظ (يوم)')->numeric()->toggleable(),
                TextColumn::make('archived_at')->label('أُرشف')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('مؤرشف')
                    ->nullable()
                    ->attribute('archived_at')
                    ->default(false),
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    SendTestLogAction::make(),
                    ArchiveProjectAction::make(),
                ]),
            ])
            ->toolbarActions([]);
    }
}
