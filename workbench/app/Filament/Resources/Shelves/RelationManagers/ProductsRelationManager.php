<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Shelves\RelationManagers;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * Read-only on the View page (Filament's default), editable on the Edit page.
 */
class ProductsRelationManager extends RelationManager
{
    use InteractsWithSpreadsheetGrid;

    protected static string $relationship = 'products';

    public function table(Table $table): Table
    {
        return $table
            ->header(SpreadsheetGrid::toolbar())
            ->columns([
                GridColumn::make('name')->required(),
            ]);
    }
}
