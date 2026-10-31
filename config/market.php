<?php

return [
    'backfill_days' => (int) env('MARKET_BACKFILL_DAYS', 365),
    'timeout_seconds' => (int) env('MARKET_TIMEOUT_SECONDS', 20),

    // Fracción del volumen diario medio que se asume poder vender sin mover el precio.
    'liquidity_participation' => (float) env('MARKET_LIQUIDITY_PARTICIPATION', 0.10),
    'liquidity_window_days' => 20,

    'yahoo' => [
        'url' => env('MARKET_YAHOO_URL', 'https://query1.finance.yahoo.com'),
        'user_agent' => env('MARKET_YAHOO_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64)'),
    ],

    'binance' => [
        'url' => env('MARKET_BINANCE_URL', 'https://data-api.binance.vision'),
    ],
];
