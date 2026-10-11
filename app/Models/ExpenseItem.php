<?php

namespace App\Models;

use App\Enums\ExpenseItemType;
use App\Enums\MovementType;
use App\Enums\Recurrence;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;

class ExpenseItem extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'expense_id',
        'category_id',
        'concept',
        'quantity',
        'unit_price',
        // 'line_total' omitted from fillable because it's a generated STORED column
        'tags',
        'item_type',
        'is_consumable',
        'end_date',
        'recurrence',
        'projected_start_date',
        'actual_start_date',
        'movement_type',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'tags' => 'array',
        'is_consumable' => 'boolean',
        'end_date' => 'date',
        'projected_start_date' => 'date',
        'actual_start_date' => 'date',
        'item_type' => ExpenseItemType::class,
        'recurrence' => Recurrence::class,
        'movement_type' => MovementType::class,
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function getEffectiveCategoryAttribute(): ?Category
    {
        return $this->category ?? $this->expense?->establishment?->category;
    }

    public function scopeWithEffectiveCategory(Builder $query): Builder
    {
        return $query
            ->with([
                'category',
                'expense.establishment.category',
            ]);
    }
}
