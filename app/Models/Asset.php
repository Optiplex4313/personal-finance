<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Asset extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_account_id',
        'instrument_id',
        'name',
        'type',
        'current_value',
        'units',
        'currency',
        'notes',
    ];

    protected $casts = [
        'type' => AssetType::class,
        'current_value' => 'decimal:2',
        'units' => 'decimal:8',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class, 'asset_id');
    }

    public function valueSnapshots(): MorphMany
    {
        return $this->morphMany(ValueSnapshot::class, 'valuable');
    }
}
