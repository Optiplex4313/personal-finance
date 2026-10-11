<?php

namespace App\Services;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\MovementType;

class BalanceExpensesService
{
    /**
     * Ejecuta el servicio para cuadrar los gastos pendientes.
     *
     * Las fechas se interpretan como días completos en la zona horaria de la app
     * (config app.display_timezone): "hasta el 30" incluye todo el día 30.
     *
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array{matched_count:int, adjusted_count:int}
     */
    public function execute(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Expense::withPendingSubexpenses();
        $matchedCount = 0;
        $adjustedCount = 0;
        $timezone = config('app.display_timezone');

        if ($startDate) {
            $query->where('date', '>=', $this->localDay($startDate, $timezone)->startOfDay()->utc());
        }

        if ($endDate) {
            $query->where('date', '<=', $this->localDay($endDate, $timezone)->endOfDay()->utc());
        }

        // chunkById y no chunk: cada gasto ajustado deja de cumplir el filtro, y con
        // chunk (paginación por OFFSET) se saltarían lotes enteros.
        $query->chunkById(100, function ($expenses) use (&$matchedCount, &$adjustedCount) {
            DB::transaction(function () use ($expenses, &$matchedCount, &$adjustedCount) {
                foreach ($expenses as $expense) {
                    // Verificamos nuevamente con la lógica exacta del modelo (diferencia > 10 céntimos)
                    if ($expense->hasPendingSubexpenses()) {
                        $matchedCount++;
                        $this->createAdjustmentItem($expense);
                        $adjustedCount++;
                    }
                }
            });
        });

        return [
            'matched_count' => $matchedCount,
            'adjusted_count' => $adjustedCount,
        ];
    }

    private function localDay(Carbon $date, string $timezone): Carbon
    {
        return Carbon::parse($date->toDateString(), $timezone);
    }

    /**
     * Crea el subgasto para ajustar la diferencia del total.
     *
     * @param Expense $expense
     * @return void
     */
    public function createAdjustmentItem(Expense $expense): void
    {
        // subexpensesDifferenceCents() devuelve: (Total de Subgastos) - (Total del Gasto)
        // Si el total del gasto es MAYOR, devuelve un valor negativo. Al invertirlo, nos da un precio positivo (añade el faltante).
        // Si el total del gasto es MENOR, devuelve un valor positivo. Al invertirlo, nos da un precio negativo (resta el sobrante).
        
        $differenceInCents = $expense->subexpensesDifferenceCents();

        if ($differenceInCents === null || $differenceInCents === 0) {
            return;
        }

        // Convertimos los céntimos a decimal (ej. -250 céntimos pasan a ser 2.50)
        $unitPrice = -($differenceInCents / 100);

        $expense->items()->create([
            'category_id' => null,
            'concept'     => 'Ajuste de descuadre',
            'quantity'    => 1,
            'unit_price'  => $unitPrice,
            'movement_type' => MovementType::ADJUSTMENT,
            'is_consumable' => false,
        ]);

        $expense->forgetSubexpensesTotal();
    }
}
