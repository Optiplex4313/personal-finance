<?php

namespace Tests\Feature\Payments;

use App\Enums\MovementType;
use App\Models\Expense;
use App\Services\BalanceExpensesService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class BalanceExpensesServiceTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    private function service(): BalanceExpensesService
    {
        return app(BalanceExpensesService::class);
    }

    public function test_adds_positive_adjustment_when_items_are_below_total(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '17.50');

        $this->service()->execute();

        $adjustment = $expense->items()->where('movement_type', MovementType::ADJUSTMENT)->sole();
        $this->assertSame('2.50', $adjustment->line_total);
        $this->assertFalse($adjustment->is_consumable);
        $this->assertSame('20.00', $this->itemsTotal($expense));
    }

    public function test_adds_negative_adjustment_when_items_are_above_total(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '2', '11.00');

        $this->service()->execute();

        $adjustment = $expense->items()->where('movement_type', MovementType::ADJUSTMENT)->sole();
        $this->assertSame('-2.00', $adjustment->line_total);
        $this->assertSame('20.00', $this->itemsTotal($expense));
    }

    public function test_balances_expense_without_items(): void
    {
        $expense = $this->createExpense('12.34');

        $this->service()->execute();

        $this->assertSame('12.34', $this->itemsTotal($expense));
    }

    public function test_tolerates_up_to_ten_cents(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '19.90');

        $summary = $this->service()->execute();

        $this->assertSame(0, $summary['adjusted_count']);
        $this->assertSame(1, $expense->items()->count());
    }

    public function test_ignores_expenses_without_total(): void
    {
        $expense = $this->createExpense(null);
        $this->addItem($expense, '1', '5.00');

        $summary = $this->service()->execute();

        $this->assertSame(0, $summary['adjusted_count']);
    }

    public function test_is_idempotent(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '15.00');

        $this->service()->execute();
        $second = $this->service()->execute();

        $this->assertSame(0, $second['adjusted_count']);
        $this->assertSame(2, $expense->items()->count());
    }

    public function test_balances_every_expense_when_there_are_more_than_one_chunk(): void
    {
        $count = 250;

        for ($i = 0; $i < $count; $i++) {
            $expense = $this->createExpense('10.00');
            $this->addItem($expense, '1', '5.00');
        }

        $summary = $this->service()->execute();

        $this->assertSame($count, $summary['adjusted_count']);
        $this->assertSame(0, Expense::withPendingSubexpenses()->count());
    }

    public function test_date_range_includes_the_whole_end_day_in_local_time(): void
    {
        // 23:30 en Madrid (verano, UTC+2) del 30 de junio.
        $lastDay = $this->createExpense('10.00', Carbon::parse('2026-06-30 21:30:00', 'UTC'));
        // 00:30 en Madrid del 1 de julio: fuera del rango.
        $nextDay = $this->createExpense('10.00', Carbon::parse('2026-06-30 22:30:00', 'UTC'));
        // 00:30 en Madrid del 1 de junio: dentro del rango.
        $firstDay = $this->createExpense('10.00', Carbon::parse('2026-05-31 22:30:00', 'UTC'));
        // 23:30 en Madrid del 31 de mayo: fuera del rango.
        $previousDay = $this->createExpense('10.00', Carbon::parse('2026-05-31 21:30:00', 'UTC'));

        $summary = $this->service()->execute(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertSame(2, $summary['adjusted_count']);
        $this->assertSame('10.00', $this->itemsTotal($lastDay));
        $this->assertSame('10.00', $this->itemsTotal($firstDay));
        $this->assertSame('0.00', $this->itemsTotal($nextDay));
        $this->assertSame('0.00', $this->itemsTotal($previousDay));
    }

    public function test_adjustments_do_not_appear_in_stock(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '15.00', ['concept' => 'Leche']);

        $this->service()->execute();

        $concepts = app(StockService::class)->query()->get()->pluck('concept')->all();
        $this->assertSame(['Leche'], $concepts);
    }
}
