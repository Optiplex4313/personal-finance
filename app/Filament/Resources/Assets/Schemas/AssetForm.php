<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AssetType;
use App\Enums\InstrumentType;
use App\Filament\Resources\BankAccounts\Schemas\BankAccountForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AssetForm
{
    public static function components(bool $includeBankAccountField = false): array
    {
        $components = [];

        if ($includeBankAccountField) {
            $components[] = Select::make('bank_account_id')
                ->label('Cuenta bancaria')
                ->relationship('bankAccount', 'name')
                ->createOptionForm(BankAccountForm::components())
                ->searchable()
                ->preload()
                ->required();
        }

        $components[] = TextInput::make('name')
            ->label('Nombre')
            ->helperText('P. ej. "Fondo indexado MSCI World" o "Depósito a 12 meses".')
            ->required()
            ->maxLength(150);
        $components[] = Select::make('type')
            ->label('Tipo')
            ->options(AssetType::class)
            ->default(AssetType::OTRO->value)
            ->required();
        $components[] = TextInput::make('current_value')
            ->label('Valor actual')
            ->numeric()
            ->prefix('€')
            ->required();
        $components[] = Select::make('instrument_id')
            ->label('Instrumento de mercado')
            ->helperText('Enlaza el activo con un instrumento para ver su gráfico y su liquidez en Mercado.')
            ->relationship('instrument', 'name', fn (Builder $query) => $query->where('type', '!=', InstrumentType::CRYPTO->value)->orderBy('symbol'))
            ->searchable()
            ->preload();
        $components[] = TextInput::make('units')
            ->label('Participaciones / títulos')
            ->helperText('Cuántas unidades tienes; se usa para calcular los días necesarios para liquidar la posición.')
            ->numeric()
            ->step('0.00000001')
            ->minValue(0);
        $components[] = TextInput::make('currency')
            ->label('Moneda')
            ->default('EUR')
            ->required()
            ->maxLength(3);
        $components[] = Textarea::make('notes')
            ->label('Notas')
            ->columnSpanFull();

        return $components;
    }

    public static function configure(Schema $schema, bool $includeBankAccountField = false): Schema
    {
        return $schema
            ->components(self::components($includeBankAccountField));
    }
}
