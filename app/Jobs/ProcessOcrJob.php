<?php

namespace App\Jobs;

use App\Enums\MovementType;
use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Services\OcrService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessOcrJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Un OCR fallido no se reintenta solo: el gasto queda marcado y el usuario decide.
     */
    public int $tries = 1;

    /**
     * Segundos. Debe superar services.ocr.timeout y quedar por debajo del
     * retry_after de la cola; si no, otro worker podría coger el mismo OCR a la vez.
     */
    public int $timeout;

    public function __construct(public string $ocrJobId)
    {
        $this->timeout = (int) config('services.ocr.timeout', 1200) + 60;
    }

    public function handle(OcrService $ocrService): void
    {
        $ocrJob = OcrJob::query()
            ->with(['expense', 'user'])
            ->findOrFail($this->ocrJobId);

        if ($ocrJob->status !== OcrJobStatus::PENDING) {
            return;
        }

        $ocrJob->forceFill([
            'started_at' => now(),
            'model' => $ocrService->getOcrServiceLabel(),
        ])->save();

        try {
            $expense = $ocrJob->expense;

            if (!$expense) {
                throw new \RuntimeException('El gasto asociado al OCR ya no existe.');
            }

            if (! $expense->isOcrAvailable()) {
                \Log::debug([
                    'expense_id' => $expense->id,
                    'items_count' => $expense->items()->count(),
                    'non_adjustment_count' => $expense->items()
                        ->where('movement_type', '!=', MovementType::ADJUSTMENT)
                        ->count(),
                    'ocr_available' => $expense->isOcrAvailable(),
                ]);
                throw new \RuntimeException('Esto no debería llegar a ocurrir. Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.');
            }

            $count = $ocrService->importExpenseItemsFromTicketOcr($expense);

            $ocrJob->forceFill([
                'status' => OcrJobStatus::COMPLETED,
                'finished_at' => now(),
                'items_imported' => $count,
                'error_message' => null,
                'error_trace' => null,
            ])->save();

            $this->notifySuccess($ocrJob, $count);
        } catch (Throwable $e) {
            $ocrJob->forceFill([
                'status' => OcrJobStatus::FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ])->save();

            $this->notifyFailure($ocrJob, $e);

            throw $e;
        }
    }

    /**
     * Se llama también cuando el worker mata el job (timeout) o este muere sin pasar
     * por el catch de handle(): sin esto el OcrJob se quedaría en PENDING para siempre.
     */
    public function failed(?Throwable $e): void
    {
        $ocrJob = OcrJob::query()->with('user')->find($this->ocrJobId);

        if (!$ocrJob || $ocrJob->status !== OcrJobStatus::PENDING) {
            return;
        }

        $e ??= new \RuntimeException('El proceso de OCR terminó de forma inesperada.');

        $ocrJob->forceFill([
            'status' => OcrJobStatus::FAILED,
            'finished_at' => now(),
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString(),
        ])->save();

        $this->notifyFailure($ocrJob, $e);
    }

    private function notifySuccess(OcrJob $ocrJob, int $count): void
    {
        if (!$ocrJob->user) {
            return;
        }

        Notification::make()
            ->title('OCR completado')
            ->body("Se crearon {$count} líneas y el gasto quedó pendiente de revisar.")
            ->success()
            ->sendToDatabase($ocrJob->user);
    }

    private function notifyFailure(OcrJob $ocrJob, Throwable $e): void
    {
        if (!$ocrJob->user) {
            return;
        }

        Notification::make()
            ->title('Error de OCR')
            ->body($e->getMessage())
            ->danger()
            ->sendToDatabase($ocrJob->user);
    }
}
