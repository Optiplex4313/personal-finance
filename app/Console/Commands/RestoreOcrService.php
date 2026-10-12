<?php

namespace App\Console\Commands;

use App\Services\OcrProviderManager;
use Illuminate\Console\Command;
use Throwable;

class RestoreOcrService extends Command
{
    protected $signature = 'ocr:restore';

    protected $description = 'Vuelve a lanzar el servicio OCR activo tras reiniciar la app (el nodo ya no tiene su instancia).';

    public function handle(OcrProviderManager $manager): int
    {
        try {
            $provider = $manager->restore();
        } catch (Throwable $e) {
            $this->error('No se pudo relanzar el servicio OCR: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($provider ? "Servicio OCR activo: {$provider->label()}" : 'No hay servicio OCR que restaurar.');

        return self::SUCCESS;
    }
}
