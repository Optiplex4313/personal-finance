<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Enums\ExpenseItemType;
use App\Enums\MovementType;
use App\Enums\Recurrence;
use App\Models\Category;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExpenseItemForm
{
    /**
     * @var array<string, string|null>
     */
    private static array $unitOfMeasureCache = [];

    private static function parseNumeric(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    public static function components(bool $includeExpenseField = false): array
    {
        $conceptSectionSchema = [];

        if ($includeExpenseField) {
            $conceptSectionSchema[] = Select::make('expense_id')
                ->relationship('expense', 'id')
                ->label('Gasto')
                ->createOptionForm(ExpenseForm::components())
                ->editOptionForm(ExpenseForm::components())
                ->required();
        }

        $conceptSectionSchema[] = Select::make('category_id')
            ->relationship('category', 'name')
            ->label('Categoría')
            ->live()
            ->createOptionForm(CategoryForm::components())
            ->editOptionForm(CategoryForm::components());
        $conceptSectionSchema[] = Select::make('movement_type')
            ->label('Tipo de movimiento')
            ->helperText('Compra suma al stock; consumo y pérdida restan (usa precio 0). Los ajustes los crea el cuadre automático.')
            ->options(MovementType::class)
            ->default(MovementType::PURCHASE->value)
            ->required();
        $conceptSectionSchema[] = TextInput::make('concept')
            ->label('Concepto')
            ->columnSpanFull();
        $conceptSectionSchema[] = TagsInput::make('tags')
            ->label('Etiquetas')
            ->columnSpanFull();

        return [
            Section::make('Concepto')
                ->columnSpanFull()
                ->columns(2)
                ->schema($conceptSectionSchema),
            Section::make('Finanzas')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('quantity')
                        ->label('Cantidad')
                        ->required()
                        ->numeric()
                        ->default(1)
                        ->reactive()
                        ->afterStateUpdated(static function (Set $set, Get $get): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $total = self::money($quantity * $unitPrice);
                            $currentTotal = self::parseNumeric($get('total_price'));

                            if (($currentTotal !== null) && (self::money($currentTotal) === $total)) {
                                return;
                            }

                            $set('total_price', $total);
                        }),
                    TextInput::make('total_price')
                        ->label('Gasto Total (€)')
                        ->numeric()
                        ->prefix('€')
                        ->dehydrated(false)
                        ->reactive()
                        ->afterStateHydrated(static function (Set $set, Get $get, mixed $state): void {
                            if (filled($state)) {
                                return;
                            }

                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $set('total_price', self::money($quantity * $unitPrice));
                        })
                        ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $total = self::parseNumeric($state);

                            if (($quantity === null) || ($quantity <= 0) || ($total === null)) {
                                return;
                            }

                            $unitPrice = self::money($total / $quantity);
                            $currentUnitPrice = self::parseNumeric($get('unit_price'));

                            if (($currentUnitPrice === null) || (self::money($currentUnitPrice) !== $unitPrice)) {
                                $set('unit_price', $unitPrice);
                            }
                        }),
                    TextInput::make('unit_price')
                        ->label('Precio Unitario (€)')
                        ->required()
                        ->numeric()
                        ->prefix('€')
                        ->suffix(static function (Get $get): ?string {
                            $unit = "und";

                            return filled($unit) ? ('/' . $unit) : null;
                        })
                        ->reactive()
                        ->afterStateUpdated(static function (Set $set, Get $get): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $total = self::money($quantity * $unitPrice);
                            $currentTotal = self::parseNumeric($get('total_price'));

                            if (($currentTotal !== null) && (self::money($currentTotal) === $total)) {
                                return;
                            }

                            $set('total_price', $total);
                        }),
                ]),
            Section::make('Clasificación y Fechas')
                ->description("Únicamente sirve para hacer estadisticas")
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('item_type')
                        ->label('Clasificación')
                        ->helperText('Indica si este subgasto es fijo o variable. Se usa para clasificar, filtrar e informar.')
                        ->options(ExpenseItemType::class)
                        ->default(ExpenseItemType::FIXED->value)
                        ->required(),
                    Select::make('recurrence')
                        ->label('Recurrencia')
                        ->helperText('Cada cuánto se repite este subgasto (si aplica). Se usa para clasificación y filtros.')
                        ->options(Recurrence::class)
                        ->default('NONE')
                        ->required(),
                    Toggle::make('is_consumable')
                        ->label('¿Consumible?')
                        ->default(true)
                        ->required(),
                    DatePicker::make('actual_start_date')
                        ->label('Fecha Inicio Real')
                        ->helperText('Fecha en la que empezó realmente (cuando lo empezaste a pagar/usar).'),
                    DatePicker::make('projected_start_date')
                        ->label('Fecha Inicio Proyectada')
                        ->helperText('Fecha estimada de inicio (planificación).'),
                    DatePicker::make('end_date')
                        ->label('Fecha Fin')
                        ->helperText('Fecha en la que termina o deja de aplicarse.'),
                ]),
        ];
    }

    public static function configure(Schema $schema, bool $includeExpenseField = false): Schema
    {
        return $schema
            ->components(self::components($includeExpenseField));
    }
}
