<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;
use App\Enums\MovementType;

class Expense extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    public bool $shouldCreateSingleItem = false;
    public ?string $singleItemConcept = null;
    public ?int $singleItemUnits = null;

    public $timestamps = false;

    protected ?string $subexpensesTotalCache = null;

    protected $fillable = [
        'establishment_id',
        'date',
        'total',
        'import_csv_path',
        'status',
        'ticket_photo_hash',
        'pending_review',  // Este campo nos sirve para saber si fue creado por OCR. Aunque cuadrase perfectamente, el OcrService lo deja marcado para revisión manual.
        'created_at',
    ];

    protected $casts = [
        'date' => 'datetime',
        'created_at' => 'datetime',
        'total' => 'decimal:2',
        'status' => ExpenseStatus::class,
        'pending_review' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'expense_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function subexpensesTotal(): string
    {
        if ($this->subexpensesTotalCache !== null) {
            return $this->subexpensesTotalCache;
        }

        $this->subexpensesTotalCache = (string) ($this->items()->sum('line_total') ?? '0');

        return $this->subexpensesTotalCache;
    }

    public function forgetSubexpensesTotal(): void
    {
        $this->subexpensesTotalCache = null;
    }

    public function hasPendingSubexpenses(): bool
    {
        if ($this->total === null) {
            return false;
        }

        $difference = abs(
            self::moneyToCents($this->subexpensesTotal()) -
            self::moneyToCents($this->total)
        );

        // Permitimos una diferencia de hasta 10 céntimos
        return $difference > 10;
    }

    public function subexpensesDifferenceCents(): ?int
    {
        if ($this->total === null) {
            return null;
        }

        return self::moneyToCents($this->subexpensesTotal()) - self::moneyToCents($this->total);
    }

    public static function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    /**
     * @param  string|int|float|null  $value
     */
    public static function formatMoney(string | int | float | null $value): string
    {
        return self::formatCents(self::moneyToCents($value));
    }

    /**
     * @param  string|int|float|null  $value
     */
    protected static function moneyToCents(string | int | float | null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    public function scopeWithPendingSubexpenses(Builder $query): Builder
    {
        $expensesTable = $this->getTable();
        $itemsTable = (new ExpenseItem)->getTable();

        return $query
            ->whereNotNull("{$expensesTable}.total")
            ->whereRaw(
                "COALESCE((SELECT SUM(line_total) FROM {$itemsTable} WHERE {$itemsTable}.expense_id = {$expensesTable}.id), 0) <> {$expensesTable}.total",
            );
    }

    public function isOcrAvailable(): bool
    {
        // // OCR is available only when there are no expense items, ignoring adjustment movements.
        return !$this->items()
            ->where('movement_type', "!=", MovementType::ADJUSTMENT)
            ->exists();
    }
}
