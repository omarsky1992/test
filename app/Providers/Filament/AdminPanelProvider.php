<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Support\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->brandName(config('app.name'))
            ->font('IBM Plex Sans Arabic')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->favicon('/icons/icon.svg')
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Stone,
            ])
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->globalSearchKeyBindings(['/', 'mod+k'])
            ->navigationGroups([
                NavigationGroup::make('العمليات اليومية'),
                NavigationGroup::make('المالية'),
                NavigationGroup::make('التقارير'),
                NavigationGroup::make('الإدارة')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render(<<<'HTML'
                <link rel="manifest" href="/manifest.webmanifest">
                <meta name="theme-color" content="#0f766e">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <link rel="apple-touch-icon" href="/icons/icon-192.png">
                <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js'); }</script>
            HTML))
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
                Authenticate::class,
            ]);
    }
}
