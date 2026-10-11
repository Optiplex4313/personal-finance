<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // movement_type no estaba en $fillable de ExpenseItem: los ajustes de descuadre
        // creados por BalanceExpensesService y OcrService se guardaron como PURCHASE
        // y consumibles, y aparecían en el stock.
        DB::statement("
            UPDATE expense_items
            SET movement_type = 'ADJUSTMENT',
                is_consumable = false
            WHERE concept = 'Ajuste de descuadre'
              AND movement_type = 'PURCHASE'
        ");
    }

    public function down(): void
    {
        //
    }
};
