<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AppAuthentication;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\SetUpTwoFactor;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\PlatformsCode;
use App\Filament\Widgets\ActiveProjectsTable;
use App\Filament\Widgets\LogVolumeChart;
use App\Filament\Widgets\PlatformStats;
use App\Filament\Widgets\TopEventsTable;
use App\Http\Middleware\Filament\Authenticate;
use App\Http\Middleware\Filament\RequireMfaForPrivilegedRoles;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name', 'Audit Log Hub'))
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->login(Login::class)
            ->profile(isSimple: false)
            ->passwordReset()
            // Plan §13: TOTP 2FA. Enforced for privileged roles by
            // RequireMfaForPrivilegedRoles; optional for everyone else.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], setUpRequiredAction: SetUpTwoFactor::class, isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireMfaForPrivilegedRoles::class)
            // Platforms Code (DGA unified design system): colours and typeface.
            ->colors(PlatformsCode::colors())
            ->font(PlatformsCode::FONT)
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('filament.partials.platforms-code-styles'))
            ->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('filament.partials.footer'))
            ->maxContentWidth(Width::Full)
            // A fixed, slim sidebar (it never collapses to icons) and no
            // global search box: each list page has its own search and filters.
            ->sidebarWidth('15rem')
            ->globalSearch(false)
            ->navigationGroups([
                NavigationGroup::make('السجلات')->collapsible(false),
                NavigationGroup::make('الأمان')->collapsible(false),
                NavigationGroup::make('الإدارة')->collapsible(false),
                NavigationGroup::make('التشغيل')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                PlatformStats::class,
                LogVolumeChart::class,
                TopEventsTable::class,
                ActiveProjectsTable::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                // Signs out users deactivated or locked during their session.
                // Persistent (security review L1): also applied to Livewire
                // update requests, not only to full page loads.
                Authenticate::class,
            ], isPersistent: true);
    }
}
