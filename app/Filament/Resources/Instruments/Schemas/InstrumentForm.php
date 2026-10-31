<?php

namespace App\Filament\Resources\Instruments\Schemas;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class InstrumentForm
{
    public static function components(): array
    {
        return [
            TextInput::make('symbol')
                ->label('Símbolo')
                ->helperText('Identificador propio y único. P. ej. "SAN" o "BTC".')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(40),
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label('Tipo')
                ->options(InstrumentType::class)
                ->required(),
            Select::make('provider')
                ->label('Proveedor de datos')
                ->options(MarketProvider::class)
                ->default(MarketProvider::YAHOO->value)
                ->required(),
            TextInput::make('provider_symbol')
                ->label('Símbolo en el proveedor')
                ->helperText('Yahoo: "SAN.MC", "IWDA.AS". Binance: "BTCEUR".')
                ->required()
                ->maxLength(80),
            TextInput::make('isin')
                ->label('ISIN')
                ->maxLength(12),
            TextInput::make('currency')
                ->label('Moneda')
                ->default('EUR')
                ->required()
                ->maxLength(20),
            Toggle::make('active')
                ->label('Sincronizar a diario')
                ->default(true),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }
}
