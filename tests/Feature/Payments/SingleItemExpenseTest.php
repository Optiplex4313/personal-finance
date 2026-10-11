<?php

namespace Tests\Feature\Payments;

use App\Models\Category;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class SingleItemExpenseTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    private function createWithSingleItem(string $total, string $concept, ?int $units): Expense
    {
        $expense = new Expense([
            'date' => now(),
            'total' => $total,
        ]);
        $expense->shouldCreateSingleItem = true;
        $expense->singleItemConcept = $concept;
        $expense->singleItemUnits = $units;
        $expense->save();

        return $expense;
    }

    public function test_creates_one_item_for_the_whole_total(): void
    {
        $category = Category::query()->create(['name' => 'Gasolina']);

        $expense = $this->createWithSingleItem('60.00', 'Gasolina', null);

        $item = $expense->items()->sole();
        $this->assertSame('60.00', $item->line_total);
        $this->assertSame($category->id, $item->category_id);
    }

    public function test_splits_total_between_units(): void
    {
        $expense = $this->createWithSingleItem('9.00', 'Cervezas', 3);

        $item = $expense->items()->sole();
        $this->assertSame('3.00', $item->unit_price);
        $this->assertSame('9.00', $item->line_total);
    }

    public function test_zero_units_counts_as_one(): void
    {
        $expense = $this->createWithSingleItem('9.00', 'Cervezas', 0);

        $this->assertSame('9.00', $expense->items()->sole()->line_total);
    }

    public function test_uneven_split_is_balanced(): void
    {
        $expense = $this->createWithSingleItem('10.00', 'Cervezas', 3);

        $this->assertSame('10.00', $this->itemsTotal($expense));
        $this->assertFalse($expense->hasPendingSubexpenses());
    }
}
