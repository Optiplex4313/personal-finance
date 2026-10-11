<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\ExpenseStatus;
use App\Enums\Recurrence;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RevolutImportService
{
    private const LOG_PREFIX = '[RevolutImportService]';

    private const CURRENCY = 'EUR';

    /**
     * Gastos ya guardados que aún pueden casar con una fila del CSV, por día local y
     * total en céntimos. Cada fila duplicada consume uno: dos cafés de 1,50 € el mismo
     * día son dos gastos, y reimportar el mismo CSV no crea ninguno.
     *
     * @var array<string, array<int, array<int, array{id: string, manual: bool}>>>
     */
    private array $existingExpenses = [];

    /**
     * @return array{
     * imported_count: int,
     * skipped_duplicate_count: int,
     * skipped_duplicates: array<int, array{
     * line: int,
     * date: string,
     * description: string,
     * total: string
     * }>,
     * duplicates_report_path: string|null
     * }
     */
    public function importCsv(string $csvPath, bool $leaveSubexpensesEmpty = false, bool $allowDuplicates = false): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($csvPath)) {
            throw new RuntimeException("No se encontró el CSV de importación en {$csvPath}.");
        }

        $rows = $this->readRows($disk->path($csvPath));

        if ($rows === []) {
            throw new RuntimeException('El CSV no contiene filas para importar.');
        }

        Log::info(self::LOG_PREFIX . ' Iniciando importación de Revolut', [
            'csv_path' => $csvPath,
            'rows_found' => count($rows),
            'leave_subexpenses_empty' => $leaveSubexpensesEmpty,
            'allow_duplicates' => $allowDuplicates,
        ]);

        $summary = [
            'imported_count' => 0,
            'skipped_count' => 0,
            'skipped' => [],
            'skipped_duplicate_count' => 0,
            'skipped_duplicates' => [],
            'duplicates_report_path' => null,
        ];

        $this->existingExpenses = [];

        DB::transaction(function () use ($rows, $csvPath, $leaveSubexpensesEmpty, $allowDuplicates, &$summary): void {
            foreach ($rows as $row) {
                $type = $this->normalizeText($row['Type'] ?? null);

                // Solo procesamos pagos con tarjeta y transferencias
                if ($type !== 'Card Payment' && $type !== 'Transfer') {
                    continue;
                }

                $amountCents = $this->moneyToCents($row['Amount'] ?? null);

                // Transferencias entrantes: son ingresos, no gastos.
                if ($type === 'Transfer' && $amountCents >= 0) {
                    continue;
                }

                $description = $this->normalizeDescription($row['Description'] ?? null);
                $date = $this->parseDate($row['Started Date'] ?? null);

                $skipReason = $this->skipReason($row, $type, $amountCents);

                if ($skipReason !== null) {
                    $summary['skipped_count']++;
                    $summary['skipped'][] = [
                        'line' => $row['__line'],
                        'date' => $date->toDateString(),
                        'description' => $description,
                        'amount' => $this->formatCents($amountCents),
                        'reason' => $skipReason,
                    ];

                    continue;
                }

                // La comisión se cobra aparte del importe: el gasto real es la suma.
                $feeCents = abs($this->moneyToCents($row['Fee'] ?? '0', allowEmpty: true));
                $totalCents = -$amountCents + $feeCents;
                $formattedTotal = $this->formatCents($totalCents);

                if (! $allowDuplicates && ($match = $this->takeExistingExpense($date, $totalCents)) !== null) {
                    $summary['skipped_duplicate_count']++;
                    $summary['skipped_duplicates'][] = [
                        'line' => $row['__line'],
                        'date' => $date->toDateString(),
                        'description' => $description,
                        'total' => $formattedTotal,
                        'existing_expense_id' => $match['id'],
                        'manual_expense_id' => $match['manual'] ? $match['id'] : null,
                    ];

                    continue;
                }

                $establishment = Establishment::query()->firstOrCreate([
                    'name' => $description,
                ]);

                $expense = Expense::query()->create([
                    'establishment_id' => $establishment->id,
                    'date' => $date->copy()->utc(),
                    'total' => $formattedTotal,
                    'import_csv_path' => $csvPath,
                    'status' => ExpenseStatus::PAID,
                    'pending_review' => false,
                    'created_at' => now(),
                ]);

                if (! $leaveSubexpensesEmpty) {
                    ExpenseItem::query()->create([
                        'expense_id' => $expense->id,
                        'category_id' => null,
                        'concept' => $description,
                        'quantity' => 1,
                        'unit_price' => $formattedTotal,
                        'tags' => [],
                        'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                        'is_consumable' => true,
                        'recurrence' => Recurrence::NONE,
                        'actual_start_date' => $date->toDateString(),
                    ]);
                }

                $summary['imported_count']++;
            }
        });

        // Almacenamiento de duplicados en un JSON si existen elementos omitidos
        if ($summary['skipped_duplicate_count'] > 0) {
            $summary['duplicates_report_path'] = $this->saveDuplicatesReport($disk, $csvPath, $summary['skipped_duplicates']);
        }

        Log::info(self::LOG_PREFIX . ' Importación de Revolut completada', [
            'csv_path' => $csvPath,
            'imported_count' => $summary['imported_count'],
            'skipped_duplicate_count' => $summary['skipped_duplicate_count'],
            'duplicates_report_path' => $summary['duplicates_report_path'],
        ]);

        return $summary;
    }

    /**
     * Guarda el array de duplicados en un archivo JSON dentro del storage local.
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param array<int, array<string, mixed>> $duplicates
     */
    private function saveDuplicatesReport($disk, string $csvPath, array $duplicates): string
    {
        // Extraemos el nombre base del archivo original (ej: 'revolut_2026_05.csv' -> 'revolut_2026_05')
        $baseName = pathinfo($csvPath, PATHINFO_FILENAME);
        
        // Generamos un nombre único usando un timestamp
        $timestamp = now()->format('Ymd_His');
        $reportPath = "revolut/duplicates/{$baseName}_duplicates_{$timestamp}.json";

        $jsonData = json_encode([
            'source_csv' => $csvPath,
            'generated_at' => now()->toIso8601String(),
            'total_skipped' => count($duplicates),
            'items' => $duplicates
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $disk->put($reportPath, $jsonData);

        return $disk->path($reportPath); // Retorna la ruta absoluta para el summary/logs
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el CSV: {$absolutePath}.");
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                return [];
            }

            $headers = array_map(fn ($header): string => $this->normalizeHeader($header), $headers);

            // Columns: Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance
            $requiredHeaders = [
                'Type',
                'Started Date',
                'Description',
                'Amount',
            ];

            foreach ($requiredHeaders as $requiredHeader) {
                if (! in_array($requiredHeader, $headers, true)) {
                    throw new RuntimeException("El CSV no contiene la columna requerida {$requiredHeader}.");
                }
            }

            $rows = [];
            $lineNumber = 1;

            while (($rawRow = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if ($rawRow === [null] || $rawRow === []) {
                    continue;
                }

                $row = [];

                foreach ($headers as $index => $header) {
                    $row[$header] = $rawRow[$index] ?? null;
                }

                $row['__line'] = $lineNumber;
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(mixed $header): string
    {
        $value = trim((string) $header);

        return ltrim($value, "\xEF\xBB\xBF");
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeDescription(mixed $value): string
    {
        $description = preg_replace('/\s+/u', ' ', trim((string) $value));

        if ($description === '' || $description === null) {
            throw new RuntimeException('La descripción del movimiento no puede estar vacía.');
        }

        return $description;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function skipReason(array $row, string $type, int $amountCents): ?string
    {
        // Pagos revertidos, rechazados o aún pendientes no son gasto (todavía).
        $state = strtoupper($this->normalizeText($row['State'] ?? 'COMPLETED'));
        if ($state !== 'COMPLETED') {
            return 'not_completed';
        }

        $currency = strtoupper($this->normalizeText($row['Currency'] ?? self::CURRENCY));
        if ($currency !== '' && $currency !== self::CURRENCY) {
            return 'currency';
        }

        // Un pago con tarjeta en positivo es un reembolso: no es un gasto.
        if ($type === 'Card Payment' && $amountCents >= 0) {
            return 'refund';
        }

        return null;
    }

    /**
     * Convierte un importe del CSV a céntimos sin pasar por float.
     * Acepta "-12.34", "12,34", "-1,234.56" y "1.234,56".
     */
    private function moneyToCents(mixed $value, bool $allowEmpty = false): int
    {
        $normalized = str_replace(['€', ' ', "\u{00A0}"], '', trim((string) $value));

        if ($normalized === '') {
            if ($allowEmpty) {
                return 0;
            }

            throw new RuntimeException('El importe del movimiento no puede estar vacío.');
        }

        $lastDot = strrpos($normalized, '.');
        $lastComma = strrpos($normalized, ',');
        $decimalSeparator = ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';

        $normalized = str_replace($thousandsSeparator, '', $normalized);
        $normalized = str_replace($decimalSeparator, '.', $normalized);

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $normalized, $matches)) {
            throw new RuntimeException("Importe inválido: {$value}");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ((int) $matches[2]) * 100 + (int) substr($fraction, 0, 2);

        // Redondeo a céntimo, mitad hacia arriba.
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $sign * $cents;
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /**
     * Revolut exporta la hora local de la cuenta; se guarda en UTC.
     */
    private function parseDate(mixed $value): Carbon
    {
        $rawDate = trim((string) $value);

        if ($rawDate === '') {
            throw new RuntimeException('La fecha de inicio del movimiento no puede estar vacía.');
        }

        $date = Carbon::createFromFormat('Y-m-d H:i:s', $rawDate, config('app.display_timezone'));

        if ($date === false || $date === null) {
            throw new RuntimeException("Fecha inválida: {$rawDate}");
        }

        return $date;
    }

    /**
     * @return array{id: string, manual: bool}|null
     */
    private function takeExistingExpense(Carbon $localDate, int $totalCents): ?array
    {
        $day = $localDate->toDateString();

        if (! isset($this->existingExpenses[$day])) {
            $this->existingExpenses[$day] = [];
            $timezone = config('app.display_timezone');

            Expense::query()
                ->whereBetween('date', [
                    Carbon::parse($day, $timezone)->startOfDay()->utc(),
                    Carbon::parse($day, $timezone)->endOfDay()->utc(),
                ])
                ->whereNotNull('total')
                ->orderBy('date')
                ->get(['id', 'total', 'import_csv_path'])
                ->each(function (Expense $expense) use ($day): void {
                    $this->existingExpenses[$day][$this->moneyToCents($expense->total)][] = [
                        'id' => $expense->id,
                        'manual' => $expense->import_csv_path === null,
                    ];
                });
        }

        if (empty($this->existingExpenses[$day][$totalCents])) {
            return null;
        }

        return array_shift($this->existingExpenses[$day][$totalCents]);
    }
}
