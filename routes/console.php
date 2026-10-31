<?php

use App\Console\Commands\BackupDatabase;
use App\Console\Commands\SyncMarketData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(BackupDatabase::class)
    ->dailyAt('03:15')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(SyncMarketData::class)
    ->dailyAt('06:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->runInBackground();
