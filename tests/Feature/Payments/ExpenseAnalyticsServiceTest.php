<?php

namespace Tests\Feature\Payments;

use App\Enums\MovementType;
use App\Models\Category;
use App\Services\ExpenseAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class ExpenseAnalyticsServiceTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    public function test_month_filter_uses_local_time(): void
    {
        // 00:30 del 1 de julio en Madrid: cuenta en julio, no en junio.
        $july = $this->createExpense('10.00', Carbon::parse('2026-06-30 22:30:00', 'UTC'));
        $this->addItem($july, '1', '10.00');
        // 00:30 del 1 de junio en Madrid: cuenta en junio.
        $june = $this->createExpense('5.00', Carbon::parse('2026-05-31 22:30:00', 'UTC'));
        $this->addItem($june, '1', '5.00');

        $service = app(ExpenseAnalyticsService::class);

        $this->assertSame(5.0, $service->totalForFilters(['month' => '2026-06']));
        $this->assertSame(10.0, $service->totalForFilters(['month' => '2026-07']));
    }

    public function test_month_total_matches_expense_totals_including_adjustments(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '15.00');
        $this->addItem($expense, '1', '5.00', ['movement_type' => MovementType::ADJUSTMENT, 'concept' => 'Ajuste de descuadre']);

        $this->assertSame(20.0, app(ExpenseAnalyticsService::class)->totalForFilters(['month' => '2026-06']));
    }

    public function test_category_filter_includes_descendants(): void
    {
        $food = Category::query()->create(['name' => 'Comida']);
        $dairy = Category::query()->create(['name' => 'Lácteos', 'parent_id' => $food->id]);
        $other = Category::query()->create(['name' => 'Ocio']);

        $expense = $this->createExpense('9.00');
        $this->addItem($expense, '1', '2.00', ['category_id' => $food->id]);
        $this->addItem($expense, '1', '3.00', ['category_id' => $dairy->id]);
        $this->addItem($expense, '1', '4.00', ['category_id' => $other->id]);

        $service = app(ExpenseAnalyticsService::class);

        $this->assertSame(5.0, $service->totalForFilters(['month' => '2026-06', 'categoryId' => $food->id]));
        $this->assertSame(9.0, $service->totalForDateRange(['month' => '2026-06', 'categoryId' => $food->id]));
    }
}
