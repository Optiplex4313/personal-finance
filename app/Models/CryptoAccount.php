<?php

namespace App\Models;

use App\Enums\CryptoTaxStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CryptoAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'instrument_id',
        'name',
        'currency',
        'wallet_reference',
        'amount_held',
        'current_value_eur',
        'tax_status',
        'notes',
    ];

    protected $casts = [
        'amount_held' => 'decimal:8',
        'current_value_eur' => 'decimal:2',
        'tax_status' => CryptoTaxStatus::class,
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    public function valueSnapshots(): MorphMany
    {
        return $this->morphMany(ValueSnapshot::class, 'valuable');
    }
}
