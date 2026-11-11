<?php

namespace App\Filament\Resources\BackupRuns\Pages;

use App\Filament\Resources\BackupRuns\BackupRunResource;
use App\Models\BackupRun;
use App\Models\Project;
use App\Services\Audit\PlatformAuditLogger;
use App\Services\Backup\BackupSpool;
use App\Services\Backup\BackupStatus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ListBackupRuns extends ListRecords
{
    protected static string $resource = BackupRunResource::class;

    public function content(Schema $schema): Schema
    {
        $status = app(BackupStatus::class)->summary();

        return $schema->components([
            Section::make('الحالة الحالية')
                ->columns(4)
                ->schema(collect($status)->map(fn (array $item) => Text::make($item['label'].': '.$item['value'])
                    ->color($item['ok'] ? 'success' : 'danger'))->values()->all()),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $canRun = fn () => auth()->user()?->can('run', BackupRun::class) ?? false;

        return [
            ActionGroup::make([
                Action::make('runFull')->label('نسخة كاملة للقاعدة الآن')
                    ->requiresConfirmation()
                    ->action(fn () => $this->request('full')),
                Action::make('runIncremental')->label('نسخة تزايدية الآن')
                    ->action(fn () => $this->request('incremental')),
                Action::make('runApp')->label('نسخ ملفات التطبيق (.env + storage)')
                    ->action(function () {
                        Artisan::queue('backup:run', ['--type' => 'app', '--user' => auth()->id()]);
                        Notification::make()->title('بدأ نسخ ملفات التطبيق')->success()->send();
                    }),
                Action::make('restoreTest')->label('اختبار استعادة الآن')
                    ->requiresConfirmation()
                    ->action(fn () => $this->request('restore-test')),
            ])
                ->label('تشغيل يدوي')
                ->icon(Heroicon::OutlinedPlay)
                ->button()
                ->visible($canRun),

            Action::make('restoreProject')
                ->label('استعادة سجلات مشروع من الأرشيف')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->visible(fn () => auth()->user()?->can('restore', BackupRun::class) ?? false)
                ->schema([
                    Select::make('project')->label('المشروع')->required()
                        ->options(fn () => Project::query()->withoutGlobalScopes()->orderBy('name')->pluck('name', 'slug')->all()),
                    DatePicker::make('from')->label('من (وقت الحدث)')->required(),
                    DatePicker::make('to')->label('إلى')->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('تُعاد السجلات من أرشيف JSONL إلى القاعدة. السجلات الموجودة لا تتكرر.')
                ->action(function (array $data) {
                    Artisan::queue('audit:restore-archive', [
                        'project' => $data['project'],
                        '--from' => $data['from'],
                        '--to' => $data['to'],
                        '--user' => auth()->id(),
                    ]);
                    app(PlatformAuditLogger::class)->record('backup.project_restore_requested', 'audit_logs', $data);
                    Notification::make()->title('بدأت الاستعادة في الخلفية')->body('ستظهر النتيجة في هذه الصفحة.')->success()->send();
                }),
        ];
    }

    private function request(string $type): void
    {
        app(BackupSpool::class)->request($type, auth()->user());
        Notification::make()
            ->title('تم إرسال الطلب لحاوية النسخ الاحتياطي')
            ->body('يبدأ التنفيذ خلال دقيقة، وتظهر النتيجة هنا.')
            ->success()
            ->send();
    }
}
