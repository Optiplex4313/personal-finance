<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\RevolutImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Forms\Components\DatePicker;
use App\Services\BalanceExpensesService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('balanceExpenses')
                    ->label('Cuadrar gastos')
                    ->icon('heroicon-o-scale')
                    ->modalHeading('Cuadrar gastos pendientes')
                    ->modalSubmitActionLabel('Ejecutar')
                    ->form([
                        DatePicker::make('start_date')
                            ->label('Desde')
                            ->helperText('Opcional. Si no se indica, se procesará todo el histórico.'),
                        DatePicker::make('end_date')
                            ->label('Hasta')
                            ->helperText('Opcional. Puede usarse sin fecha inicial.'),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $summary = app(BalanceExpensesService::class)->execute(
                                filled($data['start_date'] ?? null) ? Carbon::parse($data['start_date']) : null,
                                filled($data['end_date'] ?? null) ? Carbon::parse($data['end_date']) : null,
                            );

                            $rangeText = [];

                            if (filled($data['start_date'] ?? null)) {
                                $rangeText[] = 'desde ' . $data['start_date'];
                            }

                            if (filled($data['end_date'] ?? null)) {
                                $rangeText[] = 'hasta ' . $data['end_date'];
                            }

                            Notification::make()
                                ->success()
                                ->title('Cuadre de gastos completado')
                                ->body(sprintf(
                                    'Se revisaron %d gastos con subgastos pendientes y se crearon %d ajustes. %s',
                                    $summary['matched_count'],
                                    $summary['adjusted_count'],
                                    $rangeText !== [] ? 'Rango aplicado: ' . implode(' ', $rangeText) . '.' : 'Se procesó el histórico completo.',
                                ))
                                ->send();
                        } catch (Throwable $e) {
                            report($e);

                            Notification::make()
                                ->danger()
                                ->title('No se pudieron cuadrar los gastos')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            

            Action::make('importRevolut')
                ->label('Importar Revolut')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar extracto de Revolut')
                ->modalSubmitActionLabel('Importar')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('CSV de Revolut')
                        ->required()
                        ->disk('local')
                        ->directory('imports')
                        ->getUploadedFileNameForStorageUsing(function ($file): string {
                            $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'revolut';
                            $extension = trim((string) $file->getClientOriginalExtension());

                            return sprintf(
                                '%s-%s-%s%s',
                                now()->format('YmdHis'),
                                (string) Str::ulid(),
                                $baseName,
                                $extension !== '' ? ".{$extension}" : '',
                            );
                        })
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->helperText('El archivo se guardará en storage/app/private/imports. Se importan pagos con tarjeta y transferencias salientes completados en EUR (incluida la comisión).'),
                    Toggle::make('leave_subexpenses_empty')
                        ->label('Dejar subgastos vacíos')
                        ->helperText('Si está activo, se crearán gastos sin subgasto único.')
                        ->default(false),
                    Toggle::make('allow_duplicates')
                        ->label('Desactivar omisión de duplicados')
                        ->helperText('Si está activo, se importarán también gastos que ya existan en el mismo día con el mismo total.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    try {
                        $csvPath = $data['csv_file'] ?? null;

                        if (is_array($csvPath)) {
                            $csvPath = Arr::first($csvPath);
                        }

                        if (! is_string($csvPath) || trim($csvPath) === '') {
                            throw new \RuntimeException('Debes seleccionar un CSV válido.');
                        }

                        $summary = app(RevolutImportService::class)->importCsv(
                            $csvPath,
                            (bool) ($data['leave_subexpenses_empty'] ?? false),
                            (bool) ($data['allow_duplicates'] ?? false),
                        );

                        if ($summary['skipped_count'] > 0) {
                            $reasons = [
                                'refund' => 'reembolsos',
                                'not_completed' => 'no completados',
                                'currency' => 'en otra divisa',
                            ];

                            Notification::make()
                                ->warning()
                                ->title('Movimientos no importados')
                                ->body(collect($summary['skipped'])
                                    ->countBy('reason')
                                    ->map(fn (int $count, string $reason): string => sprintf('%d %s', $count, $reasons[$reason] ?? $reason))
                                    ->implode(', ') . '.')
                                ->persistent()
                                ->send();
                        }

                        if ($summary['skipped_duplicate_count'] > 0) {
                            $skippedPreview = array_slice($summary['skipped_duplicates'], 0, 3);
                            $skippedText = collect($skippedPreview)
                                ->map(fn (array $item): string => sprintf(
                                    '%s %s (%s)',
                                    $item['date'],
                                    $item['description'],
                                    Expense::formatMoney($item['total']),
                                ))
                                ->implode('; ');

                            if ($summary['skipped_duplicate_count'] > count($skippedPreview)) {
                                $skippedText .= sprintf(' y %d más', $summary['skipped_duplicate_count'] - count($skippedPreview));
                            }

                            Notification::make()
                                ->warning()
                                ->title('Importación completada con duplicados omitidos')
                                ->body(sprintf(
                                    'Se importaron %d gastos y se omitieron %d duplicados (mismo día y mismo total). %s',
                                    $summary['imported_count'],
                                    $summary['skipped_duplicate_count'],
                                    $skippedText !== '' ? 'Ejemplos: ' . $skippedText : '',
                                ))
                                ->send();

                            return;
                        }

                        if ($summary['imported_count'] > 0) {
                            Notification::make()
                                ->success()
                                ->title('Importación completada')
                                ->body(sprintf('Se importaron %d gastos desde Revolut.', $summary['imported_count']))
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->warning()
                            ->title('Sin movimientos importables')
                            ->body('No se encontraron filas de tipo "Pago con tarjeta" para importar.')
                            ->send();
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->danger()
                            ->title('No se pudo importar el CSV')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
