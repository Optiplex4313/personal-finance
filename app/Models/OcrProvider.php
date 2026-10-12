<?php

namespace App\Models;

use App\Enums\OcrProviderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicio OCR (paquete .celaut.bee) subido por el usuario. Como mucho uno está ACTIVE.
 */
class OcrProvider extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'service_hash',
        'bee_path',
        'uri',
        'status',
        'error_message',
    ];

    protected $casts = [
        'status' => OcrProviderStatus::class,
    ];

    public static function active(): ?self
    {
        return static::query()
            ->where('status', OcrProviderStatus::ACTIVE)
            ->latest()
            ->first();
    }

    public function label(): string
    {
        return $this->name ?: substr((string) $this->service_hash, 0, 12);
    }
}
