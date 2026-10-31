<?php

namespace Tests\Feature\Market;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Filament\Pages\Market;
use App\Filament\Resources\Instruments\Pages\CreateInstrument;
use App\Filament\Resources\Instruments\Pages\ListInstruments;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MarketPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function instrument(string $symbol = 'SAN'): Instrument
    {
        return Instrument::query()->create([
            'symbol' => $symbol,
            'name' => "Instrumento {$symbol}",
            'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO,
            'provider_symbol' => "{$symbol}.MC",
        ]);
    }

    public function test_pages_render(): void
    {
        $instrument = $this->instrument();

        foreach (['/mercado', '/instruments', '/instruments/create', "/instruments/{$instrument->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_market_page_without_instruments_points_to_the_resource(): void
    {
        $this->get('/mercado')->assertOk()->assertSee('Todavía no hay instrumentos');
    }

    public function test_market_page_selects_the_first_instrument_and_shows_liquidity(): void
    {
        $instrument = $this->instrument();
        PriceBar::query()->create([
            'instrument_id' => $instrument->id, 'date' => '2026-10-19',
            'open' => 4, 'high' => 4.3, 'low' => 3.9, 'close' => 4.25, 'volume' => 1500,
        ]);

        Livewire::test(Market::class)
            ->assertSet('instrumentId', $instrument->id)
            ->assertSee('Días para liquidar tu posición')
            ->assertSee('2026-10-19');
    }

    public function test_changing_instrument_or_range_dispatches_the_new_chart(): void
    {
        $first = $this->instrument('AAA');
        $second = $this->instrument('BBB');
        PriceBar::query()->create([
            'instrument_id' => $second->id, 'date' => '2026-10-19',
            'open' => 4, 'high' => 4.3, 'low' => 3.9, 'close' => 4.25, 'volume' => 1500,
        ]);

        Livewire::test(Market::class)
            ->assertSet('instrumentId', $first->id)
            ->set('instrumentId', $second->id)
            ->assertDispatched('market-chart-updated')
            ->call('setRange', '3M')
            ->assertSet('range', '3M');
    }

    public function test_unknown_range_is_ignored(): void
    {
        $this->instrument();

        Livewire::test(Market::class)
            ->call('setRange', 'bogus')
            ->assertSet('range', '1Y');
    }

    public function test_instrument_can_be_created_from_the_panel(): void
    {
        Livewire::test(CreateInstrument::class)
            ->fillForm([
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'type' => InstrumentType::CRYPTO->value,
                'provider' => MarketProvider::BINANCE->value,
                'provider_symbol' => 'BTCEUR',
                'currency' => 'EUR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('instruments', ['symbol' => 'BTC', 'provider' => 'BINANCE']);
    }

    public function test_sync_action_reports_new_bars(): void
    {
        $instrument = $this->instrument();
        Http::fake(['*' => Http::response(['chart' => ['result' => [[
            'timestamp' => [\Illuminate\Support\Carbon::now('UTC')->subDay()->startOfDay()->addHours(13)->getTimestamp()],
            'indicators' => ['quote' => [['open' => [4.0], 'high' => [4.2], 'low' => [3.9], 'close' => [4.1], 'volume' => [1000]]]],
        ]]]])]);

        Livewire::test(ListInstruments::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('sync')->table($instrument))
            ->assertNotified();

        $this->assertSame(1, $instrument->priceBars()->count());
    }
}
