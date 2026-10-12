<?php

namespace App\Filament\Resources\OcrJobs\Tables;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OcrJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll("5s")
            ->columns([
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (OcrJobStatus $state): string => match ($state) {
                        OcrJobStatus::PENDING => 'warning',
                        OcrJobStatus::COMPLETED => 'success',
                        OcrJobStatus::FAILED => 'danger',
                    }),
                TextColumn::make('expense.establishment.name')
                    ->label('Expense')
                    ->formatStateUsing(fn (?string $state): string => $state ?: 'No establishment')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expense.date')
                    ->label('Fecha del gasto')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Solicitado por')
                    ->toggleable(),
                TextColumn::make('model')
                    ->label('Servicio OCR')
                    ->toggleable(),
                TextColumn::make('items_imported')
                    ->label('Lineas')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('ticket_path')
                    ->label('Ticket')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Si' : 'No')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Solicitado')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label('Inicio')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('finished_at')
                    ->label('Fin')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(OcrJobStatus::class)
                    ->label('Estado'),
            ])
            ->recordActions([
                Action::make('viewError')
                    ->label('Ver error')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (OcrJob $record): bool => $record->status === OcrJobStatus::FAILED
                        && (filled($record->error_message) || filled($record->error_trace)))
                    ->modalHeading('Detalle del error')
                    ->modalContent(fn (OcrJob $record) => view('filament.resources.ocr-jobs.error-log', [
                        'record' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
            ]);
    }
}
