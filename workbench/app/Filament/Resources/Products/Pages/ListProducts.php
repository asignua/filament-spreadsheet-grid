<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    use InteractsWithSpreadsheetGrid;

    protected static string $resource = ProductResource::class;
}
