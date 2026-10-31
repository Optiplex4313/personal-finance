<?php

namespace App\Services\Market;

use App\Models\Instrument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceProvider implements MarketDataProvider
{
    private const MAX_KLINES = 1000;

    public function dailyBars(Instrument $instrument, CarbonInterface $from, CarbonInterface $to): array
    {
        $response = Http::baseUrl((string) config('market.binance.url'))
            ->timeout((int) config('market.timeout_seconds'))
            ->get('/api/v3/klines', [
                'symbol' => $instrument->provider_symbol,
                'interval' => '1d',
                'startTime' => $from->copy()->startOfDay()->getTimestampMs(),
                'endTime' => $to->copy()->endOfDay()->getTimestampMs(),
                'limit' => self::MAX_KLINES,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Binance respondió {$response->status()} para {$instrument->provider_symbol}.");
        }

        return collect($response->json())
            ->map(fn (array $kline): array => [
                'date' => Carbon::createFromTimestampMsUTC((int) $kline[0])->toDateString(),
                'open' => (string) $kline[1],
                'high' => (string) $kline[2],
                'low' => (string) $kline[3],
                'close' => (string) $kline[4],
                'volume' => (string) $kline[5],
            ])
            ->all();
    }
}
