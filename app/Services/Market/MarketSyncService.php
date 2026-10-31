<?php

namespace App\Services\Market;

use App\Enums\MarketProvider;
use App\Models\Instrument;
use App\Models\PriceBar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketSyncService
{
    /**
     * Descarga las velas diarias cerradas que faltan y devuelve cuántas se han guardado.
     */
    public function sync(Instrument $instrument, ?int $backfillDays = null): int
    {
        $today = Carbon::now('UTC')->startOfDay();
        $lastDate = $instrument->priceBars()->max('date');

        $from = $lastDate !== null
            ? Carbon::parse($lastDate, 'UTC')->addDay()
            : $today->copy()->subDays($backfillDays ?? (int) config('market.backfill_days'));

        $to = $today->copy()->subDay();

        if ($from->gt($to)) {
            return 0;
        }

        $rows = collect($this->provider($instrument->provider)->dailyBars($instrument, $from, $to))
            ->filter(fn (array $bar): bool => $bar['date'] <= $to->toDateString())
            ->map(fn (array $bar): array => [
                'id' => (string) Str::uuid(),
                'instrument_id' => $instrument->id,
                ...$bar,
            ])
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        PriceBar::query()->upsert(
            $rows->all(),
            ['instrument_id', 'date'],
            ['open', 'high', 'low', 'close', 'volume'],
        );

        return $rows->count();
    }

    public function provider(MarketProvider $provider): MarketDataProvider
    {
        return match ($provider) {
            MarketProvider::YAHOO => app(YahooProvider::class),
            MarketProvider::BINANCE => app(BinanceProvider::class),
        };
    }
}
