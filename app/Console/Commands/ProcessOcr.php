<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Expense;
use App\Services\OcrService;

class ProcessOcr extends Command
{
    /**
     * El nombre y la firma del comando de consola.
     *
     * @var string
     */
    // EJEMPLO: php artisan user:inactive
    protected $signature = 'process:ocr';

    /**
     * La descripción del comando de consola.
     *
     * @var string
     */
    protected $description = 'Procesa un OCR de prueba para un gasto específico.';

    /**
     * Ejecutar el comando.
     */
    public function handle()
    {
        // Lógica del comando
        $this->info('Ejecutando comando de procesamiento de OCR de prueba...');

        $expense = Expense::first(); // Solo para prueba, en la realidad deberías obtener el gasto relacionado al OCR Job
        $service = app(OcrService::class);
        $service->importExpenseItemsFromTicketOcr($expense);

        $this->info("Finalizado.");
    }
}
