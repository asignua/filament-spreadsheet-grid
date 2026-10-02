<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\ScopedProducts;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Filament\Resources\ScopedProducts\Pages\ListScopedProducts;
use Workbench\App\Models\Product;

/**
 * A resource whose query hides archived products, and whose grid writes through a custom path.
 */
class ScopedProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'scoped-products';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('archived', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->header(SpreadsheetGrid::toolbar())
            ->columns([
                GridColumn::make('name')->required(),
                GridColumn::make('price')->number(min: 0),
                // Not an attribute: only the custom write path knows what to do with it.
                GridColumn::make('label.upper')->text()->maxLength(10),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListScopedProducts::route('/')];
    }
}
