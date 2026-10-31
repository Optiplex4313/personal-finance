<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketProvider: string implements HasLabel
{
    case YAHOO = 'YAHOO';
    case BINANCE = 'BINANCE';

    public function getLabel(): string
    {
        return match ($this) {
            self::YAHOO => 'Yahoo Finance',
            self::BINANCE => 'Binance',
        };
    }
}
