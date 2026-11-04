<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Coolsam\Modules\ModulesPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Core\Filament\Pages\PhpInfo;
use Modules\Core\Filament\Pages\Swagger;
use Modules\Core\Filament\Pages\Welcome;
use Modules\Core\Filament\Plugins\ContentLanguageSwitchPlugin;
use Modules\Core\Filament\Plugins\EnvironmentIndicatorPlugin;
use Modules\Core\Filament\Plugins\LanguageSwitchPlugin;
use Modules\Core\Http\Middleware\AddContext;
use Modules\Core\Http\Middleware\ApplyBackofficeContentLocale;
use Modules\Core\Http\Middleware\ApplyDatabaseSettingsOverlay;
use Modules\Core\Http\Middleware\LocalizationMiddleware;
use Modules\Core\Http\Middleware\PreviewMiddleware;

final class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        /**
         * App panel theme CSS is built by Vite. {@see \Filament\Support\Commands\AssetsCommand} copies
         * registered styles from {@see \Filament\Support\Assets\Asset::getPath()} into the public Filament
         * asset tree; a remote placeholder path marks this entry as non-local so that step is skipped,
         * while {@see Css::html()} resolves the real URL when the panel renders.
         */
        FilamentAsset::register([
            Css::make('app-css', 'https://__vite__.invalid/app.css')->html(static fn (): string => Vite::asset('resources/css/app.css')),
            Css::make('admin-css', asset('css/admin.css')),
            Js::make('sidebar-scroll', asset('js/sidebar-scroll.js')),
            Js::make('sidebar-accordion', asset('js/sidebar-accordion.js')),
        ], 'admin');
    }

    public function panel(Panel $panel): Panel
    {
        $panel
            // GENERAL
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(static function (): string {
                $app_name = config('app.name');

                return sprintf('%s %s', is_string($app_name) ? $app_name : 'Laravel', __('Admin'));
            })
            ->brandLogo(static function (): ?string {
                $logo = config('app.logo');

                return is_string($logo) ? $logo : null;
            })
            ->favicon(static function (): ?string {
                $favicon = config('app.favicon');

                return is_string($favicon) ? $favicon : null;
            })
            // Prefetching is deliberately off: every row in a resource table links to
            // the record's edit page, and Filament asks Livewire to preload those links
            // on hover. Preloading builds the edit page server-side, which takes the
            // editorial lease, so merely scrolling a list locked records for 15 minutes.
            ->spa(hasPrefetching: false)
            ->maxContentWidth(Width::Full)
            ->unsavedChangesAlerts()
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::hex('#80C027'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                Welcome::class,
                Swagger::class,
                PhpInfo::class,
            ])
            // The application's own groups and Core, which every installation has, are
            // listed here. Every optional module registers its own group, with its own
            // icon, from its Filament plugin, so adding a module needs no change in this
            // file. Filament sorts groups by their position in this list and appends the
            // ones registered later, which keeps the modules in plugin discovery order
            // behind these three.
            ->navigationGroups([
                'app.navigation.health' => NavigationGroup::make()
                    ->label(static fn (): string => (string) __('app.navigation.health'))
                    ->icon(Heroicon::OutlinedHeart),
                'app.navigation.documentation' => NavigationGroup::make()
                    ->label(static fn (): string => (string) __('app.navigation.documentation'))
                    ->icon(Heroicon::OutlinedDocumentText),
                'app.navigation.core' => NavigationGroup::make()
                    ->label(static fn (): string => (string) __('app.navigation.core'))
                    ->icon(Heroicon::OutlinedBolt),
            ])
            ->widgets([
            ])
            ->plugins([
                ModulesPlugin::make(),
                EnvironmentIndicatorPlugin::make(),
                LanguageSwitchPlugin::make(),
                ContentLanguageSwitchPlugin::make(),
            ])
            // AUTHENTICATION
            ->login()
            ->profile()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            // TODO: da attivare a sviluppi finiti
            // ->requiresMultiFactorAuthentication()
            ->revealablePasswords(false)
            ->authGuard('admin')
            // NOTIFICATIONS
            // Native bell reading the shared `notifications` table (same store the SPA
            // tray uses), so import-finished notifications surface in the backoffice too.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // MIDDLEWARES
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
                // The panel does not use the "web" group, so the settings overlay has
                // to be listed here too. It stays above the locale resolver: app.locale
                // is a dotted setting like any other and would otherwise overwrite the
                // language resolved for this user.
                ApplyDatabaseSettingsOverlay::class,
                LocalizationMiddleware::class,
            ])
            // Persistent, unlike the block above: Livewire update requests reach the
            // panel through the "web" group, which tags them as the "app" surface, so
            // without this every table sort in the backoffice would be logged as SPA
            // traffic. Preview stays session-backed on admin and must survive the same
            // Livewire round-trips, otherwise a table refresh would drop the overlay.
            ->middleware([
                AddContext::class . ':admin',
                PreviewMiddleware::class . ':session',
                // The content language of the backoffice, apart from the interface language: a form save is a
                // Livewire update and must write the same translation the page was opened in.
                ApplyBackofficeContentLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);

        return $panel;
    }
}
