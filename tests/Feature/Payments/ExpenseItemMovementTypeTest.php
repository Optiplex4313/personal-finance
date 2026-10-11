<?php

namespace Tests\Feature\Payments;

use App\Enums\MovementType;
use App\Models\ExpenseItem;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class ExpenseItemMovementTypeTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    public function test_movement_type_is_persisted(): void
    {
        $expense = $this->createExpense('10.00');

        foreach (MovementType::cases() as $type) {
            $item = $this->addItem($expense, '1', '0', ['movement_type' => $type]);

            $this->assertSame($type, $item->fresh()->movement_type);
        }
    }

    public function test_movement_type_defaults_to_purchase(): void
    {
        $expense = $this->createExpense('10.00');

        $item = ExpenseItem::query()->create([
            'expense_id' => $expense->id,
            'concept' => 'Pan',
            'quantity' => 1,
            'unit_price' => '10.00',
        ]);

        $this->assertSame(MovementType::PURCHASE, $item->fresh()->movement_type);
    }

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $expense = $this->createExpense('7.47');

        $item = $this->addItem($expense, '3', '2.49');

        $this->assertSame('7.47', $item->fresh()->line_total);
    }

    public function test_stock_counts_purchases_minus_consumption_and_loss_and_ignores_adjustments(): void
    {
        $expense = $this->createExpense('10.00');

        $this->addItem($expense, '6', '1.00', ['concept' => 'Huevos']);
        $this->addItem($expense, '2', '0', ['concept' => 'Huevos', 'movement_type' => MovementType::CONSUMPTION]);
        $this->addItem($expense, '1', '0', ['concept' => 'Huevos', 'movement_type' => MovementType::LOSS]);
        $this->addItem($expense, '1', '4.00', ['concept' => 'Ajuste de descuadre', 'movement_type' => MovementType::ADJUSTMENT]);

        $rows = app(StockService::class)->query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Huevos', $rows[0]->concept);
        $this->assertEquals(3, $rows[0]->remaining_quantity);
        $this->assertEquals(6, $rows[0]->purchased_quantity);
        $this->assertEquals(3, $rows[0]->consumed_quantity);
    }

    public function test_ocr_is_available_when_only_adjustments_exist(): void
    {
        $expense = $this->createExpense('10.00');
        $this->addItem($expense, '1', '10.00', ['movement_type' => MovementType::ADJUSTMENT]);

        $this->assertTrue($expense->isOcrAvailable());

        $this->addItem($expense, '1', '1.00');

        $this->assertFalse($expense->isOcrAvailable());
    }

    public function test_migration_fixes_adjustments_saved_as_purchases(): void
    {
        $expense = $this->createExpense('20.00');
        $wrong = $this->addItem($expense, '1', '5.00', ['concept' => 'Ajuste de descuadre', 'is_consumable' => true]);
        $real = $this->addItem($expense, '1', '15.00', ['concept' => 'Leche']);

        $migration = require database_path('migrations/2026_10_11_000001_fix_adjustment_items_saved_as_purchase.php');
        $migration->up();

        $this->assertSame(MovementType::ADJUSTMENT, $wrong->fresh()->movement_type);
        $this->assertFalse($wrong->fresh()->is_consumable);
        $this->assertSame(MovementType::PURCHASE, $real->fresh()->movement_type);
    }
}
