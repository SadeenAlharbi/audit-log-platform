<?php

namespace App\Filament\Support;

use App\Services\ApiKeys\IssuedApiKey;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * The modal that shows a new API key and HMAC secret exactly once
 * (plan §5: "the key is shown once and stored as a hash").
 */
final class IssuedKeyPresenter
{
    /** @return array{key: string, secret: string|null, name: string, prefix: string} */
    public static function arguments(IssuedApiKey $issued): array
    {
        return [
            'key' => $issued->plainKey,
            'secret' => $issued->plainSecret,
            'name' => $issued->key->name,
            'prefix' => $issued->key->key_prefix,
        ];
    }

    public static function action(): Action
    {
        return Action::make('showIssuedKey')
            ->modalHeading(fn (array $arguments) => 'مفتاح API جديد: '.($arguments['name'] ?? ''))
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalIconColor('warning')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('تم الحفظ، إغلاق')
            ->closeModalByClickingAway(false)
            ->schema(fn (array $arguments) => array_filter([
                Text::make('انسخ القيم الآن. لن تظهر مرة أخرى، والمنصة تحفظ المفتاح كـ hash فقط.')
                    ->color('danger'),
                TextEntry::make('plain_key')
                    ->label('مفتاح API (Authorization: Bearer)')
                    ->state($arguments['key'] ?? '')
                    ->copyable()
                    ->fontFamily('mono'),
                filled($arguments['secret'] ?? null)
                    ? TextEntry::make('plain_secret')
                        ->label('سر التوقيع HMAC (X-Signature)')
                        ->state($arguments['secret'])
                        ->copyable()
                        ->fontFamily('mono')
                    : null,
            ]));
    }
}
