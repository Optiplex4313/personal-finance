<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Services\Market\MarketSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMarketData extends Command
{
    protected $signature = 'market:sync {--symbol= : Sincroniza solo este símbolo} {--backfill= : Días a descargar si el instrumento no tiene histórico}';

    protected $description = 'Descarga las velas diarias cerradas de los instrumentos activos.';

    public function handle(MarketSyncService $service): int
    {
        $instruments = Instrument::query()
            ->where('active', true)
            ->when($this->option('symbol'), fn ($query, string $symbol) => $query->where('symbol', $symbol))
            ->orderBy('symbol')
            ->get();

        if ($instruments->isEmpty()) {
            $this->warn('No hay instrumentos activos que sincronizar.');

            return self::SUCCESS;
        }

        $backfill = $this->option('backfill') !== null ? (int) $this->option('backfill') : null;
        $failures = 0;

        foreach ($instruments as $instrument) {
            try {
                $count = $service->sync($instrument, $backfill);
                $this->info("{$instrument->symbol}: {$count} velas.");
            } catch (Throwable $exception) {
                $failures++;
                Log::warning("market:sync falló para {$instrument->symbol}: {$exception->getMessage()}");
                $this->error("{$instrument->symbol}: {$exception->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
