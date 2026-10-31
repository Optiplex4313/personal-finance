<?php

namespace App\Services\Market;

use App\Models\Instrument;
use Carbon\CarbonInterface;

interface MarketDataProvider
{
    /**
     * Velas diarias entre $from y $to, ambos incluidos.
     *
     * @return list<array{date: string, open: string, high: string, low: string, close: string, volume: string|null}>
     */
    public function dailyBars(Instrument $instrument, CarbonInterface $from, CarbonInterface $to): array;
}
