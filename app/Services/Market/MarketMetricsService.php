<?php

namespace App\Services\Market;

use App\Models\Instrument;
use App\Models\PriceBar;
use Illuminate\Support\Collection;

class MarketMetricsService
{
    public const SMA_PERIODS = [20, 50, 200];

    /**
     * Datos listos para el gráfico: velas, volumen y medias móviles de los últimos $days días
     * (todo el histórico si es null). Las medias se calculan sobre todo el histórico para que
     * no empiecen vacías al recortar el rango.
     *
     * @return array{candles: list<array>, volume: list<array>, sma: array<int, list<array>>}
     */
    public function chart(Instrument $instrument, ?int $days): array
    {
        $bars = $this->bars($instrument);
        $closes = $bars->map(fn (PriceBar $bar): float => (float) $bar->close)->all();
        $smas = array_map(fn (int $period): array => $this->sma($closes, $period), self::SMA_PERIODS);

        $firstIndex = $days === null ? 0 : max(0, $bars->count() - $days);
        $candles = [];
        $volume = [];
        $smaSeries = array_fill_keys(self::SMA_PERIODS, []);

        foreach ($bars->slice($firstIndex)->values() as $offset => $bar) {
            $time = $bar->date->toDateString();
            $open = (float) $bar->open;
            $close = (float) $bar->close;

            $candles[] = ['time' => $time, 'open' => $open, 'high' => (float) $bar->high, 'low' => (float) $bar->low, 'close' => $close];

            if ($bar->volume !== null) {
                $volume[] = ['time' => $time, 'value' => (float) $bar->volume, 'up' => $close >= $open];
            }

            foreach (self::SMA_PERIODS as $i => $period) {
                $value = $smas[$i][$firstIndex + $offset];

                if ($value !== null) {
                    $smaSeries[$period][] = ['time' => $time, 'value' => $value];
                }
            }
        }

        return ['candles' => $candles, 'volume' => $volume, 'sma' => $smaSeries];
    }

    /**
     * Media móvil simple alineada con $values; null mientras no hay $period datos.
     *
     * @param  list<float>  $values
     * @return list<float|null>
     */
    public function sma(array $values, int $period): array
    {
        $result = [];
        $sum = 0.0;

        foreach ($values as $i => $value) {
            $sum += $value;

            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }

            $result[] = $i >= $period - 1 ? $sum / $period : null;
        }

        return $result;
    }

    /**
     * Liquidez según las últimas velas con volumen. Todo es null si el instrumento no tiene volumen
     * (p. ej. fondos de inversión).
     *
     * @return array{avg_volume: ?float, avg_turnover: ?float, units: float, days_to_liquidate: ?float, last_close: ?float, last_date: ?string}
     */
    public function liquidity(Instrument $instrument): array
    {
        $window = (int) config('market.liquidity_window_days');
        $last = $instrument->priceBars()->orderByDesc('date')->first();
        $recent = $instrument->priceBars()
            ->whereNotNull('volume')
            ->orderByDesc('date')
            ->limit($window)
            ->get();

        $avgVolume = $recent->isEmpty() ? null : $recent->avg(fn (PriceBar $bar): float => (float) $bar->volume);
        $avgTurnover = $recent->isEmpty() ? null : $recent->avg(fn (PriceBar $bar): float => (float) $bar->volume * (float) $bar->close);
        $units = $this->positionUnits($instrument);

        return [
            'avg_volume' => $avgVolume,
            'avg_turnover' => $avgTurnover,
            'units' => $units,
            'days_to_liquidate' => $this->daysToLiquidate($units, $avgVolume),
            'last_close' => $last ? (float) $last->close : null,
            'last_date' => $last?->date->toDateString(),
        ];
    }

    public function daysToLiquidate(float $units, ?float $avgVolume): ?float
    {
        $participation = (float) config('market.liquidity_participation');

        if ($units <= 0 || $avgVolume === null || $avgVolume <= 0 || $participation <= 0) {
            return null;
        }

        return $units / ($participation * $avgVolume);
    }

    /**
     * Unidades en cartera: participaciones de los activos enlazados y cantidad de las cuentas cripto.
     */
    public function positionUnits(Instrument $instrument): float
    {
        return (float) $instrument->assets()->sum('units') + (float) $instrument->cryptoAccounts()->sum('amount_held');
    }

    /**
     * @return Collection<int, PriceBar>
     */
    private function bars(Instrument $instrument): Collection
    {
        return $instrument->priceBars()
            ->orderBy('date')
            ->get(['date', 'open', 'high', 'low', 'close', 'volume']);
    }
}
