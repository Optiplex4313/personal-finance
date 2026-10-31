<?php

namespace App\Filament\Resources\Instruments\Tables;

use App\Enums\InstrumentType;
use App\Models\Instrument;
use App\Services\Market\MarketSyncService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class InstrumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('symbol')
            ->columns([
                TextColumn::make('symbol')
                    ->label('Símbolo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('provider')
                    ->label('Proveedor')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('price_bars_count')
                    ->label('Velas')
                    ->counts('priceBars')
                    ->alignEnd(),
                TextColumn::make('last_bar_date')
                    ->label('Última vela')
                    ->state(fn (Instrument $record) => $record->priceBars()->max('date'))
                    ->date(),
                IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(InstrumentType::class),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Sincronizar')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Instrument $record, MarketSyncService $service): void {
                        try {
                            $count = $service->sync($record);
                        } catch (Throwable $exception) {
                            Notification::make()->title('No se pudo sincronizar')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title("{$record->symbol}: {$count} velas nuevas")->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
