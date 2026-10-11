<?php

namespace App\Services;

use App\Models\ExpenseItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpenseAnalyticsService
{
    public function categoryOptions(): array
    {
        return \App\Models\Category::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (\App\Models\Category $category): array => [
                $category->id => $this->formatCategoryLabel($category),
            ])
            ->all();
    }

    public function tableQuery(array $filters): Builder
    {
        return $this->baseItemQuery($filters)
            ->select([
                'expense_items.*',
            ])
            ->selectRaw('expenses.date as expense_date')
            ->selectRaw('expenses.total as expense_total')
            ->selectRaw('expenses.id as expense_id')
            ->selectRaw('establishments.name as establishment_name')
            ->selectRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría') as effective_category_name")
            ->selectRaw('COALESCE(expense_items.category_id, establishments.category_id) as effective_category_id')
            ->orderByDesc('expenses.date')
            ->orderByDesc('expense_items.line_total');
    }

    public function groupedByEffectiveCategory(array $filters, int $limit = 8): Collection
    {
        return $this->collapseTopResults(
            $this->baseAggregationQuery($filters)
                ->selectRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría') as label")
                ->selectRaw('SUM(expense_items.line_total) as total')
                ->groupByRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría')")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'label' => (string) $row->label,
                    'total' => (float) $row->total,
                ]),
            $limit,
        );
    }

    public function groupedByEstablishment(array $filters, int $limit = 8): Collection
    {
        return $this->collapseTopResults(
            $this->baseAggregationQuery($filters)
                ->selectRaw("COALESCE(establishments.name, 'Sin establecimiento') as label")
                ->selectRaw('SUM(expense_items.line_total) as total')
                ->groupByRaw("COALESCE(establishments.name, 'Sin establecimiento')")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'label' => (string) $row->label,
                    'total' => (float) $row->total,
                ]),
            $limit,
        );
    }

    public function totalForFilters(array $filters): float
    {
        return (float) $this->baseAggregationQuery($filters)->sum('expense_items.line_total');
    }

    /**
     * Mantiene el nombre por compatibilidad con tus widgets, 
     * pero ahora calcula el total del mes ignorando la categoría.
     */
    public function totalForDateRange(array $filters): float
    {
        $filtersWithoutCategory = $filters;
        unset($filtersWithoutCategory['categoryId']);

        return (float) $this->baseAggregationQuery($filtersWithoutCategory)->sum('expense_items.line_total');
    }

    public function selectedCategoryLabel(array $filters): ?string
    {
        $categoryId = $filters['categoryId'] ?? null;

        if (! filled($categoryId)) {
            return null;
        }

        $category = \App\Models\Category::query()->find($categoryId);

        if (! $category) {
            return null;
        }

        return $this->formatCategoryLabel($category);
    }

    protected function baseAggregationQuery(array $filters): Builder
    {
        return $this->baseItemQuery($filters);
    }

    /**
     * Consulta base modificada para filtrar por el mes seleccionado ('YYYY-MM')
     */
    protected function baseItemQuery(array $filters): Builder
    {
        return ExpenseItem::query()
            ->join('expenses', 'expense_items.expense_id', '=', 'expenses.id')
            ->leftJoin('establishments', 'expenses.establishment_id', '=', 'establishments.id')
            ->leftJoin('categories as item_categories', 'expense_items.category_id', '=', 'item_categories.id')
            ->leftJoin('categories as establishment_categories', 'establishments.category_id', '=', 'establishment_categories.id')
            ->when(
                filled($filters['month'] ?? null),
                function (Builder $query) use ($filters) {
                    try {
                        // El mes es el mes local (app.display_timezone); las fechas se guardan en UTC.
                        $date = CarbonImmutable::parse($filters['month'] . '-01', config('app.display_timezone'));

                        return $query
                            ->where('expenses.date', '>=', $date->startOfMonth()->utc())
                            ->where('expenses.date', '<', $date->startOfMonth()->addMonth()->utc());
                    } catch (\Throwable) {
                        return $query;
                    }
                }
            )
            ->when(
                filled($filters['categoryId'] ?? null),
                function (Builder $query) use ($filters) {
                    $categoryIds = $this->categoryAndDescendantIds((string) $filters['categoryId']);

                    return $query->whereRaw(
                        'COALESCE(expense_items.category_id, establishments.category_id) IN ('
                            . implode(',', array_fill(0, count($categoryIds), '?'))
                            . ')',
                        $categoryIds,
                    );
                },
            );
    }

    /**
     * Una categoría y todos sus hijos (a cualquier profundidad), para que filtrar
     * por una categoría "padre" incluya también los productos de sus categorías hijas.
     *
     * @return array<int, string>
     */
    protected function categoryAndDescendantIds(string $categoryId): array
    {
        $category = \App\Models\Category::query()->find($categoryId);

        if (! $category) {
            return [$categoryId];
        }

        return $category->descendants()
            ->pluck('id')
            ->push($category->id)
            ->all();
    }

    protected function collapseTopResults(Collection $items, int $limit): Collection
    {
        $sorted = $items
            ->sortByDesc('total')
            ->values();

        if ($sorted->count() <= $limit) {
            return $sorted;
        }

        $topItems = $sorted->take($limit)->values();
        $otherTotal = $sorted->slice($limit)->sum('total');

        return $topItems->push([
            'label' => 'Otros',
            'total' => (float) $otherTotal,
        ]);
    }

    protected function formatCategoryLabel(\App\Models\Category $category): string
    {
        $ancestors = $category->ancestor_names;

        if ($ancestors === []) {
            return $category->name;
        }

        return implode(' / ', array_merge($ancestors, [$category->name]));
    }
}