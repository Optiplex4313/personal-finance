<?php

namespace App\Filament\Pages;

use App\Models\OcrProvider;
use Filament\Pages\Page;

class OcrService extends Page
{
    protected static string $routePath = '/ocr-service';

    protected static ?string $title = 'Servicio OCR';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Servicio OCR';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.ocr-service';

    public function getProvider(): ?OcrProvider
    {
        return OcrProvider::query()->latest()->first();
    }
}
