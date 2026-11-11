<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        $max = (int) config('audit.retention.max_days', 395);

        return $schema->components([
            Section::make('بيانات المشروع')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, string $operation) {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    // The slug identifies the project in the X-Project header,
                    // so it cannot change after creation.
                    TextInput::make('slug')
                        ->label('المعرّف (slug)')
                        ->helperText('يُرسل في الترويسة X-Project، ولا يمكن تغييره بعد الإنشاء.')
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(Project::class, 'slug', ignoreRecord: true)
                        ->disabledOn('edit')
                        ->dehydrated(fn (string $operation) => $operation === 'create'),

                    Textarea::make('description')
                        ->label('الوصف')
                        ->rows(3)
                        ->columnSpanFull(),

                    TextInput::make('retention_days')
                        ->label('مدة الاحتفاظ (أيام)')
                        ->helperText("الحد الأقصى للمنصة {$max} يومًا. السجلات الأقدم تُؤرشف ثم تُحذف.")
                        ->numeric()
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue($max)
                        ->default((int) config('audit.retention.default_days', 365)),

                    Toggle::make('is_active')
                        ->label('نشط')
                        ->helperText('عند الإيقاف تُرفض كل طلبات هذا المشروع.')
                        ->default(true)
                        ->hiddenOn('create'),
                ]),
        ]);
    }
}
