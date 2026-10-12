<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OcrService
{

    public function __construct(
        private BalanceExpensesService $balance_expenses_service,
        private OcrProviderManager $providers,
    ) {
    }

    /**
     * Prefijo para identificar logs de este servicio
     */
    private const LOG_PREFIX = '[OcrService]';

    /**
     * Servicio OCR que se usará, para dejarlo registrado en el OcrJob.
     */
    public function getOcrServiceLabel(): ?string
    {
        return $this->providers->activeLabel();
    }

    public function importExpenseItemsFromTicketOcr(Expense $expense): int
    {
        $expenseId = $expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando importación de items desde OCR', [
            'expense_id' => $expenseId,
        ]);

        try {
            $items = $this->extractTicketItems($expense);
            $count = $this->importExpenseItems($expense, $items);
            $this->balance_expenses_service->createAdjustmentItem($expense);

            Log::info(self::LOG_PREFIX . ' Importación desde OCR completada', [
                'expense_id' => $expenseId,
                'imported_count' => $count,
            ]);

            return $count;

        } catch (Exception $e) {
            Log::error(self::LOG_PREFIX . ' Error en importación desde OCR', [
                'expense_id' => $expenseId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<int, array{concept: string, quantity: string, unit_price: string}> $items
     */
    private function importExpenseItems(Expense $expense, array $items): int
    {
        $expenseId = $expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando importación de items', [
            'expense_id' => $expenseId,
            'items_to_import' => count($items),
        ]);

        return DB::transaction(function () use ($expense, $items, $expenseId): int {
            $expense->refresh();

            // Borra el posible ajuste de descuadre.
            ExpenseItem::query()
                ->where('expense_id', $expense->id)
                ->where('movement_type', MovementType::ADJUSTMENT)
                ->delete();

            // Mas estrictto que isOcrAvailable, pues requiere que no exista absolutamente ninguna línea, incluyendo ajustes de descuadre.
            if ($expense->items()->exists()) {
                $existingCount = $expense->items()->count();
                Log::warning(self::LOG_PREFIX . ' El gasto ya tiene líneas existentes', [
                    'expense_id' => $expenseId,
                    'existing_items_count' => $existingCount,
                ]);
                throw new RuntimeException('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.');
            }

            if (empty($items)) {
                Log::warning(self::LOG_PREFIX . ' No hay items para importar', [
                    'expense_id' => $expenseId,
                ]);
                throw new RuntimeException('El OCR no devolvió ninguna línea.');
            }

            $importedCount = 0;
            foreach ($items as $index => $item) {
                Log::debug(self::LOG_PREFIX . ' Creando ExpenseItem', [
                    'expense_id' => $expenseId,
                    'item_index' => $index,
                    'concept' => $item['concept'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'product_id' => null,
                    'concept' => $item['concept'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tags' => [],
                    'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                    'recurrence' => Recurrence::NONE,
                    'is_consumable' => true,
                ]);

                $importedCount++;
            }

            $expense->forceFill(['pending_review' => true])->save();

            Log::info(self::LOG_PREFIX . ' Items importados correctamente', [
                'expense_id' => $expenseId,
                'imported_count' => $importedCount,
                'pending_review' => true,
            ]);

            return $importedCount;
        });
    }

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    private function extractTicketItems(Expense $expense): array
    {
        $startTime = microtime(true);
        $expenseId = $expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando extracción OCR', [
            'expense_id' => $expenseId,
            'expense_total' => $expense->total,
            'service' => $this->providers->activeLabel(),
        ]);

        try {
            $ticketHash = trim((string) $expense->ticket_photo_hash);

            if ($ticketHash === '') {
                throw new RuntimeException('El gasto no tiene foto de ticket.');
            }

            if (! Storage::disk('local')->exists($ticketHash)) {
                throw new RuntimeException("No se encontró el ticket: {$ticketHash}.");
            }

            $baseUrl = $this->providers->baseUrl();

            if ($baseUrl === null) {
                throw new RuntimeException('No hay ningún servicio OCR activo. Sube un archivo .celaut.bee en "Servicio OCR".');
            }

            $response = Http::timeout((int) config('services.ocr.timeout'))
                ->acceptJson()
                ->withBody(Storage::disk('local')->get($ticketHash), $this->ticketMimeType($ticketHash))
                ->post($baseUrl . '/ocr');

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'El servicio OCR falló (HTTP %d): %s',
                    $response->status(),
                    $this->trimForError($response->body())
                ));
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                Log::error(self::LOG_PREFIX . ' La respuesta del servicio OCR no es JSON válido', [
                    'expense_id' => $expenseId,
                    'content_preview' => $this->trimForError($response->body()),
                ]);
                throw new RuntimeException('La respuesta del servicio OCR no es un JSON válido.');
            }

            $this->syncExpenseMetadataFromTicket($expense, $decoded);
            $items = $this->parseResponsePayload($decoded, $expenseId);

            Log::info(self::LOG_PREFIX . ' Extracción OCR completada exitosamente', [
                'expense_id' => $expenseId,
                'items_count' => count($items),
                'total_duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

            return $items;

        } catch (Exception $e) {
            Log::error(self::LOG_PREFIX . ' Error en extracción OCR', [
                'expense_id' => $expenseId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'total_duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

            throw $e;
        }
    }

    private function ticketMimeType(string $ticketHash): string
    {
        return Storage::disk('local')->mimeType($ticketHash) ?: 'application/octet-stream';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    private function parseResponsePayload(array $decoded, $expenseId = null): array
    {
        Log::debug(self::LOG_PREFIX . ' Parseando contenido de respuesta', [
            'expense_id' => $expenseId,
            'payload_keys' => array_keys($decoded),
        ]);

        $items = isset($decoded['items']) ? $decoded['items'] : null;

        if (!is_array($items)) {
            Log::error(self::LOG_PREFIX . ' JSON no contiene clave "items" válida', [
                'expense_id' => $expenseId,
                'decoded_keys' => array_keys($decoded),
                'items_type' => gettype($items),
            ]);
            throw new RuntimeException('El OCR no devolvió un JSON válido con la clave "items".');
        }

        Log::debug(self::LOG_PREFIX . ' Items encontrados en respuesta', [
            'expense_id' => $expenseId,
            'raw_items_count' => count($items),
        ]);

        $normalized = [];
        $skippedCount = 0;
        $skippedReasons = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: no es un array";
                continue;
            }

            $conceptRaw = $item['concept'] ?? $item['name'] ?? '';
            $concept = trim((string) $conceptRaw);
            if (function_exists('mb_substr')) {
                $concept = mb_substr($concept, 0, 255);
            } else {
                $concept = substr($concept, 0, 255);
            }

            $quantityRaw = $item['quantity'] ?? null;
            $unitPriceRaw = $item['unit_price'] ?? $item['price'] ?? null;

            $quantity = $this->parseDecimal($quantityRaw);
            $unitPrice = $this->parseDecimal($unitPriceRaw);

            if (blank($concept)) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: concept vacío";
                continue;
            }

            if ($quantity === null) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: quantity inválido (" . var_export($quantityRaw, true) . ")";
                continue;
            }

            if ($unitPrice === null) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: unit_price inválido (" . var_export($unitPriceRaw, true) . ")";
                continue;
            }

            if ($quantity <= 0) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: quantity <= 0 ({$quantity})";
                continue;
            }

            if ($unitPrice < 0) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: unit_price < 0 ({$unitPrice})";
                continue;
            }

            $normalized[] = [
                'concept' => $concept,
                'quantity' => $this->formatDecimal($quantity, 3),
                'unit_price' => $this->formatDecimal($unitPrice, 2),
            ];
        }

        if ($skippedCount > 0) {
            Log::warning(self::LOG_PREFIX . ' Algunos items fueron omitidos', [
                'expense_id' => $expenseId,
                'skipped_count' => $skippedCount,
                'skipped_reasons' => $skippedReasons,
            ]);
        }

        if (empty($normalized)) {
            Log::error(self::LOG_PREFIX . ' No se obtuvieron líneas válidas después del parsing', [
                'expense_id' => $expenseId,
                'raw_items_count' => count($items),
                'skipped_count' => $skippedCount,
            ]);
            throw new RuntimeException('El OCR no devolvió ninguna línea válida.');
        }

        Log::info(self::LOG_PREFIX . ' Parsing completado', [
            'expense_id' => $expenseId,
            'valid_items_count' => count($normalized),
            'skipped_count' => $skippedCount,
        ]);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function syncExpenseMetadataFromTicket(Expense $expense, array $decoded): void
    {
        $expense->refresh();

        $establishmentName = $this->extractTextValue($decoded, [
            'establishment',
            'establishment_name',
            'merchant',
            'merchant_name',
            'store',
            'vendor',
            'business_name',
        ]);

        $categoryName = $this->extractTextValue($decoded, [
            'category',
            'category_name',
            'establishment_category',
        ]);

        if ($establishmentName === null && $categoryName === null) {
            return;
        }

        DB::transaction(function () use ($expense, $establishmentName, $categoryName): void {
            $category = null;
            if ($categoryName !== null) {
                $category = $this->firstOrCreateCategory($categoryName);
            }

            $establishment = $expense->establishment;

            if ($establishment === null && $establishmentName !== null) {
                $establishment = $this->firstOrCreateEstablishment($establishmentName, $category?->id);
                $expense->forceFill([
                    'establishment_id' => $establishment->id,
                ])->save();
            } elseif ($establishment !== null && $category !== null && $establishment->category_id === null) {
                $establishment->forceFill([
                    'category_id' => $category->id,
                ])->save();
            }
        });
    }

    private function firstOrCreateEstablishment(string $name, ?string $categoryId = null): Establishment
    {
        $normalizedName = $this->normalizeTicketText($name);

        $establishment = Establishment::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$normalizedName])
            ->first();

        if ($establishment !== null) {
            if ($categoryId !== null && $establishment->category_id === null) {
                $establishment->forceFill(['category_id' => $categoryId])->save();
            }

            return $establishment;
        }

        return Establishment::query()->create([
            'name' => $normalizedName,
            'category_id' => $categoryId,
        ]);
    }

    private function firstOrCreateCategory(string $name): Category
    {
        $normalizedName = $this->normalizeTicketText($name);

        $category = Category::query()
            ->withTrashed()
            ->whereRaw('LOWER(name) = LOWER(?)', [$normalizedName])
            ->first();

        if ($category !== null) {
            if ($category->trashed()) {
                $category->restore();
            }

            return $category;
        }

        return Category::query()->create([
            'name' => $normalizedName,
            'parent_id' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractTextValue(array $decoded, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }

            $value = $decoded[$key];

            if (is_string($value) || is_numeric($value)) {
                $normalized = $this->normalizeTicketText((string) $value);
                return $normalized !== '' ? $normalized : null;
            }

            if (is_array($value)) {
                foreach (['name', 'label', 'value', 'title'] as $nestedKey) {
                    if (isset($value[$nestedKey]) && (is_string($value[$nestedKey]) || is_numeric($value[$nestedKey]))) {
                        $normalized = $this->normalizeTicketText((string) $value[$nestedKey]);
                        return $normalized !== '' ? $normalized : null;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeTicketText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    private function trimForError(string $value): string
    {
        $value = trim($value);

        if (function_exists('mb_strlen')) {
            $len = mb_strlen($value);
        } else {
            $len = strlen($value);
        }

        if ($len <= 500) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            $prefix = mb_substr($value, 0, 500);
        } else {
            $prefix = substr($value, 0, 500);
        }

        return $prefix . '…';
    }

    private function parseDecimal($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace([' ', "\t", "\n", "\r"], '', $value);
        $value = preg_replace('/[^\d,.\-]/', '', $value);

        if (!is_string($value) || $value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function formatDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
