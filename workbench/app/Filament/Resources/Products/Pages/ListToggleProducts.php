<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;

/**
 * Same table, with the page-level mode switch (off until the user turns it on).
 */
class ListToggleProducts extends ListProducts
{
    protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
    {
        return $grid->toggleable();
    }
}
