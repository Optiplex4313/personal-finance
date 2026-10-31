<?php

namespace Tests\Feature\Market;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Models\Instrument;
use App\Models\PriceBar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
    }

    private function instrument(MarketProvider $provider, string $symbol, string $providerSymbol, InstrumentType $type): Instrument
    {
        return Instrument::query()->create([
            'symbol' => $symbol,
            'name' => $symbol,
            'type' => $type,
            'provider' => $provider,
            'provider_symbol' => $providerSymbol,
            'currency' => 'EUR',
        ]);
    }

    private function yahooPayload(array $days): array
    {
        $timestamps = array_map(fn (string $day) => Carbon::parse("{$day} 13:30:00", 'UTC')->getTimestamp(), array_keys($days));

        return ['chart' => ['result' => [[
            'timestamp' => $timestamps,
            'indicators' => ['quote' => [[
                'open' => array_column($days, 0),
                'high' => array_column($days, 1),
                'low' => array_column($days, 2),
                'close' => array_column($days, 3),
                'volume' => array_column($days, 4),
            ]]],
        ]], 'error' => null]];
    }

    public function test_yahoo_bars_are_stored_and_today_is_excluded(): void
    {
        $instrument = $this->instrument(MarketProvider::YAHOO, 'SAN', 'SAN.MC', InstrumentType::STOCK);

        Http::fake(['*/v8/finance/chart/SAN.MC*' => Http::response($this->yahooPayload([
            '2026-10-16' => [4.0, 4.2, 3.9, 4.1, 1000],
            '2026-10-19' => [4.1, 4.3, 4.0, 4.25, 1500],
            '2026-10-20' => [4.25, 4.3, 4.2, 4.28, 200],
        ]))]);

        $this->artisan('market:sync')->assertSuccessful();

        $this->assertSame(2, $instrument->priceBars()->count());
        $bar = $instrument->priceBars()->orderBy('date')->first();
        $this->assertSame('2026-10-16', $bar->date->toDateString());
        $this->assertSame('4.10000000', $bar->close);
        $this->assertSame('1000.00000000', $bar->volume);
    }

    public function test_yahoo_rows_without_close_are_skipped(): void
    {
        $instrument = $this->instrument(MarketProvider::YAHOO, 'SAN', 'SAN.MC', InstrumentType::STOCK);

        Http::fake(['*' => Http::response($this->yahooPayload([
            '2026-10-16' => [null, null, null, null, null],
            '2026-10-19' => [4.1, 4.3, 4.0, 4.25, 1500],
        ]))]);

        $this->artisan('market:sync')->assertSuccessful();

        $this->assertSame(1, $instrument->priceBars()->count());
    }

    public function test_binance_klines_are_stored_with_volume(): void
    {
        $instrument = $this->instrument(MarketProvider::BINANCE, 'BTC', 'BTCEUR', InstrumentType::CRYPTO);
        $open = Carbon::parse('2026-10-19', 'UTC')->getTimestampMs();

        Http::fake(['*/api/v3/klines*' => Http::response([
            [$open, '90000.10', '91000.00', '89000.00', '90500.50', '12.5', $open + 86399999, '1131256.25', 100, '6', '540000', '0'],
        ])]);

        $this->artisan('market:sync', ['--symbol' => 'BTC'])->assertSuccessful();

        $bar = $instrument->priceBars()->first();
        $this->assertSame('2026-10-19', $bar->date->toDateString());
        $this->assertSame('90500.50000000', $bar->close);
        $this->assertSame('12.50000000', $bar->volume);
    }

    public function test_sync_is_idempotent_and_only_requests_missing_days(): void
    {
        $instrument = $this->instrument(MarketProvider::YAHOO, 'SAN', 'SAN.MC', InstrumentType::STOCK);
        PriceBar::query()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-10-16',
            'open' => 4, 'high' => 4.2, 'low' => 3.9, 'close' => 4.1, 'volume' => 1000,
        ]);

        Http::fake(['*' => Http::response($this->yahooPayload([
            '2026-10-19' => [4.1, 4.3, 4.0, 4.25, 1500],
        ]))]);

        $this->artisan('market:sync')->assertSuccessful();
        $this->artisan('market:sync')->assertSuccessful();

        $this->assertSame(2, $instrument->priceBars()->count());
        Http::assertSent(fn ($request) => (int) $request['period1'] === Carbon::parse('2026-10-17', 'UTC')->getTimestamp());
    }

    public function test_one_failing_instrument_does_not_abort_the_rest(): void
    {
        $bad = $this->instrument(MarketProvider::YAHOO, 'BAD', 'BAD.XX', InstrumentType::STOCK);
        $good = $this->instrument(MarketProvider::YAHOO, 'SAN', 'SAN.MC', InstrumentType::STOCK);

        Http::fake([
            '*/v8/finance/chart/BAD.XX*' => Http::response([], 404),
            '*/v8/finance/chart/SAN.MC*' => Http::response($this->yahooPayload([
                '2026-10-19' => [4.1, 4.3, 4.0, 4.25, 1500],
            ])),
        ]);

        $this->artisan('market:sync')->assertFailed();

        $this->assertSame(0, $bad->priceBars()->count());
        $this->assertSame(1, $good->priceBars()->count());
    }

    public function test_inactive_instruments_are_ignored(): void
    {
        $instrument = $this->instrument(MarketProvider::YAHOO, 'SAN', 'SAN.MC', InstrumentType::STOCK);
        $instrument->update(['active' => false]);

        Http::fake();

        $this->artisan('market:sync')->assertSuccessful();

        Http::assertNothingSent();
    }
}
