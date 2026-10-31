<?php

namespace App\Filament\Resources\CryptoAccounts\Schemas;

use App\Enums\CryptoTaxStatus;
use App\Enums\InstrumentType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CryptoAccountForm
{
    private const COMMON_CURRENCIES = ['BTC', 'ETH', 'ERG', 'USDT', 'USDC', 'ADA', 'SOL'];

    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('P. ej. "Wallet Ledger BTC" o "Cuenta Binance".')
                ->required()
                ->maxLength(150),
            TextInput::make('currency')
                ->label('Criptomoneda')
                ->required()
                ->maxLength(20)
                ->datalist(self::COMMON_CURRENCIES),
            TextInput::make('wallet_reference')
                ->label('Wallet / exchange')
                ->helperText('Dirección de la wallet o nombre del exchange donde está custodiada.')
                ->maxLength(255),
            TextInput::make('amount_held')
                ->label('Cantidad')
                ->numeric()
                ->step('0.00000001')
                ->required(),
            Select::make('instrument_id')
                ->label('Instrumento de mercado')
                ->helperText('Enlaza la cuenta con una criptomoneda para ver su gráfico y su liquidez en Mercado.')
                ->relationship('instrument', 'name', fn (Builder $query) => $query->where('type', InstrumentType::CRYPTO->value)->orderBy('symbol'))
                ->searchable()
                ->preload(),
            TextInput::make('current_value_eur')
                ->label('Valor actual estimado')
                ->numeric()
                ->prefix('€'),
            Select::make('tax_status')
                ->label('Estado fiscal')
                ->helperText('Control manual sobre si esta cuenta está detectada por el fisco.')
                ->options(CryptoTaxStatus::class)
                ->default(CryptoTaxStatus::UNKNOWN->value)
                ->required(),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
