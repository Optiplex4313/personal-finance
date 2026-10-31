@php
    $instrument = $this->instrument();
    $liquidity = $this->liquidity();
    $number = fn (?float $value, int $decimals = 0): string => $value === null ? 'No disponible' : number_format($value, $decimals, ',', '.');
@endphp

<x-filament-panels::page>
    @if ($this->instruments()->isEmpty())
        <x-filament::section>
            <p>Todavía no hay instrumentos. Crea uno en
                <a href="{{ \App\Filament\Resources\Instruments\InstrumentResource::getUrl('create') }}" class="text-primary-600 underline">Instrumentos</a>
                y sincronízalo para ver su gráfico.</p>
        </x-filament::section>
    @else
        <div style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem">
            <div style="width:100%;max-width:24rem">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="instrumentId">
                        @foreach ($this->instruments() as $id => $option)
                            <option value="{{ $id }}">{{ $option->symbol }} — {{ $option->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <x-filament::tabs>
                @foreach (array_keys(\App\Filament\Pages\Market::RANGES) as $option)
                    <x-filament::tabs.item
                        :active="$this->range === $option"
                        wire:click="setRange('{{ $option }}')"
                    >
                        {{ $option === 'MAX' ? 'Todo' : $option }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem">
            <x-filament::section>
                <x-slot name="heading">Último cierre</x-slot>
                <p class="text-2xl font-semibold">{{ $number($liquidity['last_close'], 2) }} {{ $instrument->currency }}</p>
                <p class="text-sm text-gray-500">{{ $liquidity['last_date'] ?? 'Sin datos: sincroniza el instrumento' }}</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Volumen medio ({{ config('market.liquidity_window_days') }} d)</x-slot>
                <p class="text-2xl font-semibold">{{ $number($liquidity['avg_volume']) }}</p>
                <p class="text-sm text-gray-500">títulos por día</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Volumen negociado medio</x-slot>
                <p class="text-2xl font-semibold">{{ $number($liquidity['avg_turnover']) }} {{ $instrument->currency }}</p>
                <p class="text-sm text-gray-500">precio × volumen, por día</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Días para liquidar tu posición</x-slot>
                <p class="text-2xl font-semibold">{{ $liquidity['days_to_liquidate'] === null ? 'No disponible' : $number($liquidity['days_to_liquidate'], 2) }}</p>
                <p class="text-sm text-gray-500">
                    {{ $number($liquidity['units'], 4) }} unidades, vendiendo el {{ (int) round(config('market.liquidity_participation') * 100) }} % del volumen diario
                </p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div
                wire:ignore
                wire:key="market-chart"
                x-data="marketChart(@js($this->chart()))"
                x-on:market-chart-updated.window="update($event.detail.chart)"
                class="w-full"
            >
                <div x-ref="chart" style="width:100%;height:28rem"></div>
                <p class="mt-2 text-xs text-gray-500">
                    Medias móviles: <span style="color:#f59e0b">SMA 20</span> · <span style="color:#3b82f6">SMA 50</span> · <span style="color:#a855f7">SMA 200</span>
                </p>
            </div>
        </x-filament::section>

        @script
            <script>
                Alpine.data('marketChart', (initial) => ({
                    chart: null,
                    series: {},

                    init() {
                        const dark = document.documentElement.classList.contains('dark')
                        const { createChart, CandlestickSeries, HistogramSeries, LineSeries } = window.LightweightCharts

                        this.chart = createChart(this.$refs.chart, {
                            autoSize: true,
                            localization: { locale: 'es-ES' },
                            layout: {
                                background: { color: 'transparent' },
                                textColor: dark ? '#d1d5db' : '#374151',
                            },
                            grid: {
                                vertLines: { color: dark ? '#1f2937' : '#e5e7eb' },
                                horzLines: { color: dark ? '#1f2937' : '#e5e7eb' },
                            },
                            rightPriceScale: { borderVisible: false },
                            timeScale: { borderVisible: false },
                        })

                        this.series.candles = this.chart.addSeries(CandlestickSeries, {
                            upColor: '#16a34a', downColor: '#dc2626',
                            wickUpColor: '#16a34a', wickDownColor: '#dc2626', borderVisible: false,
                        })

                        this.series.volume = this.chart.addSeries(HistogramSeries, {
                            priceFormat: { type: 'volume' },
                            priceScaleId: 'volume',
                        })
                        this.chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } })

                        this.series.sma = {}
                        ;[[20, '#f59e0b'], [50, '#3b82f6'], [200, '#a855f7']].forEach(([period, color]) => {
                            this.series.sma[period] = this.chart.addSeries(LineSeries, {
                                color, lineWidth: 1, priceLineVisible: false, lastValueVisible: false,
                            })
                        })

                        this.update(initial)
                    },

                    update(data) {
                        this.series.candles.setData(data.candles)
                        this.series.volume.setData(data.volume.map((bar) => ({
                            time: bar.time,
                            value: bar.value,
                            color: bar.up ? 'rgba(22,163,74,0.5)' : 'rgba(220,38,38,0.5)',
                        })))
                        Object.entries(this.series.sma).forEach(([period, line]) => line.setData(data.sma[period] ?? []))
                        this.chart.timeScale().fitContent()
                    },

                    destroy() {
                        this.chart?.remove()
                    },
                }))
            </script>
        @endscript
    @endif
</x-filament-panels::page>
