<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products\Pages;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;

/**
 * Starts in spreadsheet mode and remembers the user's choice in the session.
 */
class ListPersistedToggleProducts extends ListProducts
{
    protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
    {
        return $grid->toggleable(default: true, persist: true);
    }
}
