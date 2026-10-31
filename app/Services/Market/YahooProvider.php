<?php

namespace App\Services\Market;

use App\Models\Instrument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YahooProvider implements MarketDataProvider
{
    public function dailyBars(Instrument $instrument, CarbonInterface $from, CarbonInterface $to): array
    {
        $response = Http::baseUrl((string) config('market.yahoo.url'))
            ->withUserAgent((string) config('market.yahoo.user_agent'))
            ->timeout((int) config('market.timeout_seconds'))
            ->get('/v8/finance/chart/'.rawurlencode($instrument->provider_symbol), [
                'interval' => '1d',
                'period1' => $from->copy()->startOfDay()->getTimestamp(),
                'period2' => $to->copy()->endOfDay()->getTimestamp(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Yahoo respondió {$response->status()} para {$instrument->provider_symbol}.");
        }

        $result = $response->json('chart.result.0');

        if (! is_array($result)) {
            $error = $response->json('chart.error.description') ?? 'respuesta sin datos';

            throw new RuntimeException("Yahoo no devolvió datos para {$instrument->provider_symbol}: {$error}.");
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $bars = [];

        foreach ($timestamps as $i => $timestamp) {
            $close = $quote['close'][$i] ?? null;

            if ($close === null) {
                continue;
            }

            $bars[] = [
                'date' => Carbon::createFromTimestampUTC((int) $timestamp)->toDateString(),
                'open' => (string) ($quote['open'][$i] ?? $close),
                'high' => (string) ($quote['high'][$i] ?? $close),
                'low' => (string) ($quote['low'][$i] ?? $close),
                'close' => (string) $close,
                'volume' => isset($quote['volume'][$i]) ? (string) $quote['volume'][$i] : null,
            ];
        }

        return $bars;
    }
}
