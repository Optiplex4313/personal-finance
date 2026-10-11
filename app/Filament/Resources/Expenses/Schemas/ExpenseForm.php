<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use App\Models\Category;
use App\Models\Establishment;
use App\Filament\Resources\Establishments\Schemas\EstablishmentForm;

class ExpenseForm
{
    public static function components(): array
    {
        return [
            Select::make('establishment_id')
                ->label('Establishment')
                ->options(fn () => Establishment::orderBy('name')->pluck('name', 'id'))
                ->placeholder('No establishment')
                ->relationship('establishment', 'name')
                ->preload()
                ->createOptionForm(EstablishmentForm::components())
                ->searchable()
                ->nullable(),
            DateTimePicker::make('date')
                ->label('Fecha y Hora')
                ->seconds(false)
                ->required(),
            TextInput::make('total')
                ->label('Total (€)')
                ->numeric()
                ->prefix('€')
                ->required(fn (Get $get): bool => (bool) $get('create_single_item')),
            Select::make('status')
                ->label('Estado')
                ->options(ExpenseStatus::class)
                ->default('PAID')
                ->required(),
            Toggle::make('pending_review')
                ->label('Pendiente de Revisar')
                ->helperText('Desmarcar esta opción para indicar que el gasto ha sido revisado y aprobado.'),
            Toggle::make('create_single_item')
                ->label('Crear subgasto único por el total')
                ->helperText('Crea automáticamente un subgasto con el importe total del gasto.')
                ->default(false)
                ->dehydrated(function ($livewire): bool {
                    if (method_exists($livewire, 'getFormContext')) {
                        return $livewire->getFormContext() === 'create';
                    }

                    return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                })
                ->visible(function ($livewire): bool {
                    if (method_exists($livewire, 'getFormContext')) {
                        return $livewire->getFormContext() === 'create';
                    }

                    return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                })
                ->reactive()
                ->columnSpanFull(),
            TextInput::make('singleItemConcept')
                ->label('Concepto del subgasto único')
                ->helperText('Específica el concepto del subgasto que se creará automáticamente con el importe total del gasto.')
                ->dehydrated(function ($livewire): bool {
                    if (method_exists($livewire, 'getFormContext')) {
                        return $livewire->getFormContext() === 'create';
                    }

                    return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                })
                ->visible(fn (Get $get): bool => (bool) $get('create_single_item'))
                ->datalist(fn() => Category::pluck('name')->toArray()),
            TextInput::make('singleItemUnits')
                ->label('Unidades del subgasto único')
                ->numeric()
                ->minValue(1)
                ->step(1)
                ->helperText('Específica las unidades del subgasto, por defecto 1. Se dividirá el importe total del gasto entre las unidades para calcular el precio unitario.')
                ->dehydrated(function ($livewire): bool {
                    if (method_exists($livewire, 'getFormContext')) {
                        return $livewire->getFormContext() === 'create';
                    }

                    return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                })
                ->visible(fn (Get $get): bool => (bool) $get('create_single_item')),
            \Filament\Forms\Components\FileUpload::make('ticket_photo_hash')
                ->label('Foto del Ticket')
                ->image()
                ->directory('tickets')
                ->columnSpanFull()
                ->acceptedFileTypes([
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                    'image/heic',
                    'image/heif',
                ])
                ->maxSize(20480)
                ->extraInputAttributes([
                    'accept'  => 'image/*',
                    'capture' => 'environment', // 'user' = cámara frontal, 'environment' = trasera
                ])
                ->disk('local'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
