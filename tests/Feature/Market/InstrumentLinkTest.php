<?php

namespace Tests\Feature\Market;

use App\Enums\AssetType;
use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Filament\Pages\Market;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\CryptoAccounts\Pages\CreateCryptoAccount;
use App\Filament\Resources\CryptoAccounts\Pages\ListCryptoAccounts;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CryptoAccount;
use App\Models\Instrument;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstrumentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function instrument(string $symbol, InstrumentType $type): Instrument
    {
        return Instrument::query()->create([
            'symbol' => $symbol,
            'name' => "Instrumento {$symbol}",
            'type' => $type,
            'provider' => MarketProvider::YAHOO,
            'provider_symbol' => $symbol,
        ]);
    }

    private function asset(?Instrument $instrument): Asset
    {
        $account = BankAccount::query()->create(['name' => 'Broker', 'bank_name' => 'Broker']);

        return Asset::query()->create([
            'bank_account_id' => $account->id,
            'instrument_id' => $instrument?->id,
            'name' => 'Cartera',
            'type' => AssetType::ACCIONES,
            'current_value' => 100,
        ]);
    }

    public function test_asset_is_created_with_instrument_and_units(): void
    {
        $instrument = $this->instrument('SAN', InstrumentType::STOCK);
        $account = BankAccount::query()->create(['name' => 'Broker', 'bank_name' => 'Broker']);

        Livewire::test(CreateAsset::class)
            ->fillForm([
                'bank_account_id' => $account->id,
                'name' => 'Santander',
                'type' => AssetType::ACCIONES->value,
                'instrument_id' => $instrument->id,
                'units' => '250.5',
                'current_value' => '1000',
                'currency' => 'EUR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('assets', ['name' => 'Santander', 'instrument_id' => $instrument->id]);
        $this->assertSame('250.50000000', Asset::query()->where('name', 'Santander')->value('units'));
    }

    public function test_asset_form_does_not_offer_crypto_instruments(): void
    {
        $this->instrument('BTC', InstrumentType::CRYPTO);
        $stock = $this->instrument('SAN', InstrumentType::STOCK);

        Livewire::test(CreateAsset::class)
            ->assertFormFieldExists('instrument_id', checkFieldUsing: fn ($field) => array_keys($field->getOptions()) === [$stock->id]);
    }

    public function test_crypto_account_form_only_offers_crypto_instruments(): void
    {
        $this->instrument('SAN', InstrumentType::STOCK);
        $btc = $this->instrument('BTC', InstrumentType::CRYPTO);

        Livewire::test(CreateCryptoAccount::class)
            ->assertFormFieldExists('instrument_id', checkFieldUsing: fn ($field) => array_keys($field->getOptions()) === [$btc->id])
            ->fillForm([
                'name' => 'Ledger',
                'currency' => 'BTC',
                'amount_held' => '0.5',
                'instrument_id' => $btc->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('crypto_accounts', ['name' => 'Ledger', 'instrument_id' => $btc->id]);
    }

    public function test_chart_action_is_only_visible_for_linked_records(): void
    {
        $linked = $this->asset($this->instrument('SAN', InstrumentType::STOCK));
        $unlinked = $this->asset(null);

        Livewire::test(ListAssets::class)
            ->assertActionVisible(TestAction::make('chart')->table($linked))
            ->assertActionHidden(TestAction::make('chart')->table($unlinked));
    }

    public function test_crypto_chart_action_links_to_the_market_page(): void
    {
        $btc = $this->instrument('BTC', InstrumentType::CRYPTO);
        $account = CryptoAccount::query()->create(['instrument_id' => $btc->id, 'name' => 'Ledger', 'currency' => 'BTC', 'amount_held' => 1]);

        Livewire::test(ListCryptoAccounts::class)
            ->assertActionHasUrl(TestAction::make('chart')->table($account), Market::getUrl(['instrumentId' => $btc->id]));
    }

    public function test_market_page_opens_the_linked_instrument_from_the_url(): void
    {
        $this->instrument('AAA', InstrumentType::STOCK);
        $second = $this->instrument('BBB', InstrumentType::STOCK);

        Livewire::withQueryParams(['instrumentId' => $second->id])
            ->test(Market::class)
            ->assertSet('instrumentId', $second->id);
    }

    public function test_detail_pages_render_with_the_link(): void
    {
        $instrument = $this->instrument('SAN', InstrumentType::STOCK);
        $asset = $this->asset($instrument);

        $this->get("/assets/{$asset->id}")
            ->assertOk()
            ->assertSee(Market::getUrl(['instrumentId' => $instrument->id]), false);
    }
}
