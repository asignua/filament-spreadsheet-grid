<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Shelves\RelationManagers;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * allowDuplicates(): Filament keys the rows by the pivot key, which the grid does not support.
 */
class StockedProductsRelationManager extends RelationManager
{
    use InteractsWithSpreadsheetGrid;

    protected static string $relationship = 'stockedProducts';

    public function table(Table $table): Table
    {
        return $table
            ->allowDuplicates()
            ->header(SpreadsheetGrid::toolbar())
            ->columns([
                GridColumn::make('name')->required(),
            ]);
    }
}
