<?php

namespace App\Filament\Pages;

use App\Models\Instrument;
use App\Services\Market\MarketMetricsService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class Market extends Page
{
    /** Días de velas por rango; null es todo el histórico. */
    public const RANGES = [
        '3M' => 90,
        '6M' => 180,
        '1Y' => 365,
        'MAX' => null,
    ];

    protected static ?string $slug = 'mercado';

    protected static ?string $title = 'Mercado';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.market';

    #[Url]
    public ?string $instrumentId = null;

    #[Url]
    public string $range = '1Y';

    public function mount(): void
    {
        if (! $this->instruments()->has($this->instrumentId ?? '')) {
            $this->instrumentId = $this->instruments()->keys()->first();
        }

        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '1Y';
        }
    }

    /**
     * @return Collection<string, Instrument>
     */
    public function instruments(): Collection
    {
        return Instrument::query()->orderBy('symbol')->get()->keyBy('id');
    }

    public function instrument(): ?Instrument
    {
        return $this->instruments()->get($this->instrumentId ?? '');
    }

    public function chart(): array
    {
        $instrument = $this->instrument();

        return $instrument
            ? app(MarketMetricsService::class)->chart($instrument, self::RANGES[$this->range])
            : ['candles' => [], 'volume' => [], 'sma' => []];
    }

    public function liquidity(): ?array
    {
        $instrument = $this->instrument();

        return $instrument ? app(MarketMetricsService::class)->liquidity($instrument) : null;
    }

    public function updatedInstrumentId(): void
    {
        $this->dispatchChart();
    }

    public function setRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
            $this->dispatchChart();
        }
    }

    private function dispatchChart(): void
    {
        $this->dispatch('market-chart-updated', chart: $this->chart());
    }
}
