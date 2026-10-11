<?php

namespace App\Observers;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\Category;
use App\Enums\OcrJobStatus;
use App\Jobs\ProcessOcrJob;
use App\Models\OcrJob;
use App\Services\BalanceExpensesService;
use Filament\Notifications\Notification;

class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        if ($expense->items()->exists()) {
            return;
        }

        if ($expense->shouldCreateSingleItem)
        {
            # Si el concepto equivale a una categoría ya creada en DB se asocia, si no queda vacio.
            $category = Category::where('name', $expense->singleItemConcept)->first();
            $units = max(1, (int) ($expense->singleItemUnits ?? 1));

            ExpenseItem::create([
                'category_id' => $category?->id,
                'expense_id' => $expense->id,
                'concept' => $expense->singleItemConcept,
                'quantity' => $units,
                'unit_price' => round((float) $expense->total / $units, 2),
                'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                'recurrence' => Recurrence::NONE,
                'is_consumable' => true,
            ]);

            // Si el total no se divide exacto entre las unidades, el redondeo deja céntimos sueltos.
            app(BalanceExpensesService::class)->createAdjustmentItem($expense);

            return;
        }
        
        if (isset($expense->ticket_photo_hash))
        {
            $alreadyPending = OcrJob::query()
                ->where('expense_id', $expense->id)
                ->where('status', OcrJobStatus::PENDING)
                ->exists();

            if ($alreadyPending) {
                Notification::make()
                    ->warning()
                    ->title('OCR ya en cola')
                    ->body('Ya existe un proceso OCR pendiente para este gasto.')
                    ->send();
                return;
            }

            $ocrJob = OcrJob::query()->create([
                'expense_id' => $expense->id,
                'user_id' => auth()->id(),
                'status' => OcrJobStatus::PENDING,
                'ticket_path' => $expense->ticket_photo_hash,
            ]);

            ProcessOcrJob::dispatch($ocrJob->id);

            Notification::make()
                ->success()
                ->title('OCR en cola')
                ->body('Te avisaremos cuando finalice el procesamiento.')
                ->send();
        }
    }
}

