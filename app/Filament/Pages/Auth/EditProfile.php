<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

/**
 * Filament's profile page, laid out in sections with labels above the
 * fields (the default puts each label beside its field across the full
 * page width). Behaviour is unchanged: a new password still needs the
 * current one and a confirmation.
 */
class EditProfile extends BaseEditProfile
{
    public function getMaxContentWidth(): Width
    {
        return Width::FourExtraLarge;
    }

    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('البيانات الشخصية')
                ->compact()
                ->columns(2)
                ->schema([
                    $this->getNameFormComponent(),
                    $this->getEmailFormComponent(),
                ]),
            Section::make('تغيير كلمة المرور')
                ->description('اتركها فارغة إن لم ترد تغييرها.')
                ->compact()
                ->columns(2)
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent()->columnSpanFull(),
                ]),
        ]);
    }
}
