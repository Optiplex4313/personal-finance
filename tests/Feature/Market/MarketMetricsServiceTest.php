<?php

namespace Tests\Feature\Market;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CryptoAccount;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Services\Market\MarketMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarketMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function instrument(): Instrument
    {
        return Instrument::query()->create([
            'symbol' => 'SAN',
            'name' => 'Santander',
            'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO,
            'provider_symbol' => 'SAN.MC',
        ]);
    }

    /**
     * @param  list<array{0: float, 1: ?float}>  $closesAndVolumes  de la más antigua a la más reciente, acabando el 2026-10-19
     */
    private function bars(Instrument $instrument, array $closesAndVolumes): void
    {
        $last = Carbon::parse('2026-10-19');

        foreach (array_values($closesAndVolumes) as $i => [$close, $volume]) {
            PriceBar::query()->create([
                'instrument_id' => $instrument->id,
                'date' => $last->copy()->subDays(count($closesAndVolumes) - 1 - $i)->toDateString(),
                'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
                'volume' => $volume,
            ]);
        }
    }

    public function test_sma_is_null_until_the_period_is_filled(): void
    {
        $sma = (new MarketMetricsService)->sma([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertSame([null, null, 2.0, 3.0, 4.0], $sma);
    }

    public function test_chart_slices_the_range_but_computes_smas_on_the_full_history(): void
    {
        $instrument = $this->instrument();
        $this->bars($instrument, [[1.0, 10], [2.0, 10], [3.0, 10], [4.0, 10], [5.0, 10]]);

        $chart = (new MarketMetricsService)->chart($instrument, 2);

        $this->assertCount(2, $chart['candles']);
        $this->assertSame('2026-10-18', $chart['candles'][0]['time']);
        $this->assertCount(2, $chart['volume']);
        $this->assertSame([], $chart['sma'][20]);
    }

    public function test_chart_sma_values_in_a_short_period(): void
    {
        $instrument = $this->instrument();
        $this->bars($instrument, array_map(fn (int $close) => [(float) $close, 10], range(1, 25)));

        $chart = (new MarketMetricsService)->chart($instrument, 3);

        // SMA20 del día 25 = media de 6..25 = 15.5; del día 23 = media de 4..23 = 13.5
        $this->assertSame(13.5, $chart['sma'][20][0]['value']);
        $this->assertSame(15.5, $chart['sma'][20][2]['value']);
        $this->assertSame([], $chart['sma'][50]);
    }

    public function test_bars_without_volume_produce_no_volume_series(): void
    {
        $instrument = $this->instrument();
        $this->bars($instrument, [[1.0, null], [2.0, null]]);

        $chart = (new MarketMetricsService)->chart($instrument, null);

        $this->assertCount(2, $chart['candles']);
        $this->assertSame([], $chart['volume']);
    }

    public function test_liquidity_uses_the_average_volume_and_the_position(): void
    {
        config(['market.liquidity_participation' => 0.10, 'market.liquidity_window_days' => 20]);
        $instrument = $this->instrument();
        $this->bars($instrument, [[10.0, 1000], [10.0, 3000]]);

        $account = BankAccount::query()->create(['name' => 'Broker', 'bank_name' => 'Broker']);
        Asset::query()->create(['bank_account_id' => $account->id, 'instrument_id' => $instrument->id, 'name' => 'SAN', 'type' => AssetType::ACCIONES, 'current_value' => 0, 'units' => 500]);

        $liquidity = (new MarketMetricsService)->liquidity($instrument);

        $this->assertSame(2000.0, $liquidity['avg_volume']);
        $this->assertSame(20000.0, $liquidity['avg_turnover']);
        $this->assertSame(500.0, $liquidity['units']);
        // 500 / (0,10 × 2000) = 2,5 días
        $this->assertSame(2.5, $liquidity['days_to_liquidate']);
        $this->assertSame('2026-10-19', $liquidity['last_date']);
    }

    public function test_crypto_account_amount_counts_as_position(): void
    {
        $instrument = $this->instrument();
        CryptoAccount::query()->create(['instrument_id' => $instrument->id, 'name' => 'Wallet', 'currency' => 'BTC', 'amount_held' => '1.5']);

        $this->assertSame(1.5, (new MarketMetricsService)->positionUnits($instrument));
    }

    public function test_instruments_without_volume_have_no_liquidity_metrics(): void
    {
        $instrument = $this->instrument();
        $this->bars($instrument, [[10.0, null]]);

        $liquidity = (new MarketMetricsService)->liquidity($instrument);

        $this->assertNull($liquidity['avg_volume']);
        $this->assertNull($liquidity['days_to_liquidate']);
        $this->assertSame(10.0, $liquidity['last_close']);
    }

    public function test_days_to_liquidate_is_null_without_position(): void
    {
        $this->assertNull((new MarketMetricsService)->daysToLiquidate(0.0, 1000.0));
    }
}
