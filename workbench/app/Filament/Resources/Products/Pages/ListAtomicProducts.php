<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;

/**
 * Same table, all-or-nothing and autosave on.
 */
class ListAtomicProducts extends ListProducts
{
    protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
    {
        return $grid->atomic()->autosave();
    }
}
