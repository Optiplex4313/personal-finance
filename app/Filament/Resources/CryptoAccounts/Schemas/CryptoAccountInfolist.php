<?php

namespace App\Filament\Resources\CryptoAccounts\Schemas;

use App\Filament\Pages\Market;
use App\Models\CryptoAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CryptoAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles de la cuenta cripto')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('currency')
                            ->label('Criptomoneda'),
                        TextEntry::make('wallet_reference')
                            ->label('Wallet / exchange')
                            ->placeholder('Sin especificar'),
                        TextEntry::make('amount_held')
                            ->label('Cantidad'),
                        TextEntry::make('instrument.name')
                            ->label('Instrumento de mercado')
                            ->placeholder('Sin enlazar')
                            ->url(fn (CryptoAccount $record): ?string => $record->instrument_id ? Market::getUrl(['instrumentId' => $record->instrument_id]) : null),
                        TextEntry::make('current_value_eur')
                            ->label('Valor actual estimado')
                            ->money('EUR')
                            ->placeholder('Sin valorar'),
                        TextEntry::make('tax_status')
                            ->label('Estado fiscal')
                            ->badge(),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
