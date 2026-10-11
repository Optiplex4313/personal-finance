<?php

namespace Tests\Concerns;

use App\Enums\ExpenseItemType;
use App\Enums\MovementType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

trait CreatesExpenses
{
    protected function createExpense(?string $total, CarbonInterface|string|null $date = null, array $attributes = []): Expense
    {
        return Expense::query()->create([
            'date' => $date ?? Carbon::parse('2026-06-15 12:00:00', 'UTC'),
            'total' => $total,
            ...$attributes,
        ]);
    }

    protected function addItem(Expense $expense, string $quantity, string $unitPrice, array $attributes = []): ExpenseItem
    {
        return ExpenseItem::query()->create([
            'expense_id' => $expense->id,
            'concept' => 'Producto',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
            'recurrence' => Recurrence::NONE,
            'movement_type' => MovementType::PURCHASE,
            ...$attributes,
        ]);
    }

    protected function itemsTotal(Expense $expense): string
    {
        return number_format((float) $expense->items()->sum('line_total'), 2, '.', '');
    }
}
