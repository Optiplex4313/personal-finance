<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OcrProviderStatus: string implements HasLabel
{
    case ACTIVE = 'ACTIVE';
    case FAILED = 'FAILED';
    case STOPPED = 'STOPPED';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo',
            self::FAILED => 'Fallido',
            self::STOPPED => 'Parado',
        };
    }
}
