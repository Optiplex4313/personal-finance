<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Filament\Pages\Market;
use App\Models\Asset;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles del activo')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('bankAccount.name')
                            ->label('Cuenta bancaria'),
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge(),
                        TextEntry::make('current_value')
                            ->label('Valor actual')
                            ->money('EUR'),
                        TextEntry::make('instrument.name')
                            ->label('Instrumento de mercado')
                            ->placeholder('Sin enlazar')
                            ->url(fn (Asset $record): ?string => $record->instrument_id ? Market::getUrl(['instrumentId' => $record->instrument_id]) : null),
                        TextEntry::make('units')
                            ->label('Participaciones / títulos')
                            ->placeholder('Sin indicar'),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
