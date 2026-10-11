<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseItemsRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class PanelPagesTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pages_render(): void
    {
        $expense = $this->createExpense('20.00');
        $this->addItem($expense, '1', '15.00', ['concept' => 'Leche']);
        $this->addItem($expense, '1', '5.00', ['concept' => 'Ajuste de descuadre', 'movement_type' => MovementType::ADJUSTMENT]);

        foreach ([
            '/expenses',
            '/expenses/create',
            "/expenses/{$expense->id}",
            "/expenses/{$expense->id}/edit",
            '/analisis-gastos',
            '/stock',
            '/ocr-jobs',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_item_can_be_created_with_movement_type_from_the_panel(): void
    {
        $expense = $this->createExpense('0.00');

        Livewire::test(ExpenseItemsRelationManager::class, [
            'ownerRecord' => $expense,
            'pageClass' => EditExpense::class,
        ])
            ->callTableAction('create', data: [
                'concept' => 'Huevos',
                'quantity' => 2,
                'unit_price' => 0,
                'movement_type' => MovementType::CONSUMPTION->value,
                'item_type' => 'VARIABLE_IRREGULAR',
                'recurrence' => 'NONE',
                'is_consumable' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(MovementType::CONSUMPTION, $expense->items()->sole()->movement_type);
    }
}
