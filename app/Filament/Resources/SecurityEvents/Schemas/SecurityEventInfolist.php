<?php

namespace App\Filament\Resources\SecurityEvents\Schemas;

use App\Filament\Support\Labels;
use App\Models\SecurityEvent;
use App\Support\Time\DisplayTime;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SecurityEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المحاولة')->columns(3)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i:s.v'),
                TextEntry::make('reason')->label('السبب')->badge()->color('danger')
                    ->formatStateUsing(fn (string $state) => Labels::reason($state)),
                TextEntry::make('ip')->label('عنوان IP')->fontFamily('mono')->copyable(),
                TextEntry::make('project.name')->label('المشروع')->placeholder('غير معروف'),
                TextEntry::make('apiKey.name')->label('المفتاح')->placeholder('—'),
                // Unknown keys have no row: show the prefix that was sent (request_meta).
                TextEntry::make('key_prefix')->label('بادئة المفتاح')->fontFamily('mono')->placeholder('—')
                    ->state(fn (SecurityEvent $record): ?string => $record->apiKey->key_prefix ?? ($record->request_meta['key_prefix'] ?? null)),
            ]),
            Section::make('تفاصيل الطلب')->columnSpanFull()->schema([
                KeyValueEntry::make('request_meta')->label('تفاصيل الطلب')->hiddenLabel()
                    ->keyLabel('البيان')->valueLabel('القيمة')
                    ->state(fn (SecurityEvent $record) => collect($record->request_meta ?? [])
                        ->mapWithKeys(fn ($v, $k) => [(Labels::SECURITY_DETAILS[$k] ?? $k) => "\u{2068}".self::detail((string) $k, $v)."\u{2069}"])->all()),
            ]),
        ]);
    }

    /** A request detail as shown: summary rows (D21) read in Arabic and local time. */
    private static function detail(string $key, mixed $value): string
    {
        return match (true) {
            $key === 'note' && is_string($value) && str_starts_with($value, 'attempts over the per-minute sample') => 'محاولات زادت على حد التسجيل في الدقيقة، فسُجّلت بعددها في هذا الصف بدل صف لكل محاولة (القرار D21)',
            $key === 'window_start' && is_string($value) => DisplayTime::local($value)->format('Y-m-d H:i'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
