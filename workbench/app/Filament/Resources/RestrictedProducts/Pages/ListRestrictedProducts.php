<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\RestrictedProducts\Pages;

use Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\RestrictedProducts\RestrictedProductResource;

class ListRestrictedProducts extends ListRecords
{
    use InteractsWithSpreadsheetGrid;

    protected static string $resource = RestrictedProductResource::class;
}
