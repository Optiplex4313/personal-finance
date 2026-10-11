<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->path('')
            ->login()
            ->spa()
            ->databaseNotifications()
            ->globalSearchKeyBindings(['mod+shift+k'])
            ->maxContentWidth(Width::Full)
            ->topNavigation()
            ->navigationGroups([
                'Finanzas',
                'Inventario',
                'IA',
            ])
            ->plugin(\Hammadzafar05\MobileBottomNav\MobileBottomNav::make()->fromNavigation(3))
            ->plugin(\Caresome\FilamentAuthDesigner\AuthDesignerPlugin::make())
            ->plugin(\Caresome\FilamentNeobrutalism\NeobrutalismeTheme::make())
            ->colors([
                'primary' => Color::Green,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    public function boot(): void
    {
        TextColumn::macro('numericRight', function () {
            /** @var TextColumn $this */
            return $this
                ->numeric(decimalPlaces: 0)
                ->alignEnd();
        });

        TextColumn::macro('local_money', function () {
            /** @var TextColumn $this */
            return $this
                ->numeric(decimalPlaces: 2)
                ->alignEnd()
                ->suffix(' €');
        });

        Table::configureUsing(function (Table $table): void {
            $table
                ->striped()
                ->paginationPageOptions([10, 25, 50, 100])
                ->defaultPaginationPageOption(25)
                ->persistFiltersInSession()
                ->persistColumnSearchesInSession()
                ->persistSearchInSession()
                ->persistSortInSession()
                ->reorderableColumns()
                ->deferColumnManager(false);
            // Sin poll global: cada tabla abierta consultaba la base de datos cada 5 s.
            // Las vistas que lo necesitan (OCR) declaran su propio poll.
        });
    }
}