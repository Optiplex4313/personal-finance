<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpenseAnalysisByCategoryChart;
use App\Filament\Widgets\ExpenseAnalysisByEstablishmentChart;
use App\Filament\Widgets\ExpenseAnalysisItemsTable;
use App\Filament\Widgets\ExpenseAnalysisOverview;
use App\Services\ExpenseAnalyticsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class ExpenseAnalysis extends Dashboard
{
    use HasFiltersAction;

    protected static string $routePath = '/analisis-gastos';

    protected static ?string $title = 'Análisis de gastos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Análisis de gastos';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [
            ExpenseAnalysisOverview::class,
            ExpenseAnalysisByCategoryChart::class,
            ExpenseAnalysisByEstablishmentChart::class,
            ExpenseAnalysisItemsTable::class,
        ];
    }

    /**
     * Título dinámico de la página basado en los filtros aplicados
     */
    public function getTitle(): string | Htmlable
    {
        $categoryName = $this->getFilterCategoryName();

        if ($categoryName) {
            return "Análisis de gastos: {$categoryName}";
        }

        return 'Análisis de gastos general';
    }

    /**
     * Descripción / Subtítulo dinámico con el mes seleccionado
     */
    public function getSubheading(): ?string
    {
        $month = $this->filters['month'] ?? null;

        if ($month) {
            // Convertimos el formato 'YYYY-MM' a un objeto Carbon para formatearlo en texto
            $date = Carbon::parse($month . '-01');
            return "Mostrando datos de " . ucfirst($date->translatedFormat('F Y'));
        }

        return 'Mostrando el histórico completo de gastos';
    }

    /**
     * Helper para obtener el nombre de la categoría seleccionada
     */
    protected function getFilterCategoryName(): ?string
    {
        $categoryId = $this->filters['categoryId'] ?? null;

        if (! $categoryId) {
            return null;
        }

        return app(ExpenseAnalyticsService::class)->categoryOptions()[$categoryId] ?? null;
    }

    public function persistsFiltersInSession(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->label('Aplicar filtros')
                ->schema($this->getFilterSchema()),
            Action::make('clearFilters')
                ->label('Limpiar filtros')
                ->color('gray')
                ->icon('heroicon-o-x-mark')
                ->action(function (): void {
                    $this->filters = [];
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function getFilterSchema(): array
    {
        return [
            Select::make('month')
                ->label('Mes')
                ->placeholder('Todos los meses')
                ->searchable()
                ->options(function () {
                    $options = [];
                    $start = Carbon::now(config('app.display_timezone'))->startOfMonth();

                    // Generamos los últimos 24 meses de manera dinámica
                    for ($i = 0; $i < 24; $i++) {
                        $month = $start->copy()->subMonths($i);
                        // Clave: '2026-06', Valor: 'Junio 2026'
                        $options[$month->format('Y-m')] = ucfirst($month->translatedFormat('F Y'));
                    }

                    return $options;
                }),

            Select::make('categoryId')
                ->label('Categoría')
                ->placeholder('Todas')
                ->searchable()
                ->preload()
                ->options(fn (): array => app(ExpenseAnalyticsService::class)->categoryOptions()),
        ];
    }
}