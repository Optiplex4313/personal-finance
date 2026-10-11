<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;
use Filament\Tables\Columns\Summarizers\Sum;

class ExpenseItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('ID'),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable(),
                TextColumn::make('concept')
                    ->label('Concepto')
                    ->searchable(),
                TextColumn::make('movement_type')
                    ->label('Movimiento')
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Precio Unitario')
                    ->local_money()
                    ->sortable(),
                TextColumn::make('line_total')
                    ->label('Total de Línea')
                    ->summarize(Sum::make())
                    ->local_money()
                    ->sortable(),
                IconColumn::make('is_consumable')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('¿Consumible?')
                    ->boolean(),
                TextColumn::make('end_date')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Fecha Fin')
                    ->date()
                    ->sortable(),
                TextColumn::make('projected_start_date')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Inicio Proyectado')
                    ->date()
                    ->sortable(),
                TextColumn::make('actual_start_date')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Inicio Real')
                    ->date()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Clasificación')
                    ->badge(),
                TextColumn::make('recurrence')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Recurrencia')
                    ->badge(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('item_type')
                    ->options(\App\Enums\ExpenseItemType::class)
                    ->label('Tipo'),
                \Filament\Tables\Filters\SelectFilter::make('recurrence')
                    ->options(\App\Enums\Recurrence::class)
                    ->label('Recurrencia'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordActions([
                CommentsAction::make()
                    ->mentionables(fn (Model $record) => User::query()->get()),
            ]);
    }
}
