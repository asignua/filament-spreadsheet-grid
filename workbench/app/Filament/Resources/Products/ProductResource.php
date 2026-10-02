<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Products;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Product;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    /** Who sees the `cost` column (stands for `auth()->user()->isAdmin()`). */
    public static bool $showCost = false;

    public static function table(Table $table): Table
    {
        return $table
            ->header(SpreadsheetGrid::toolbar())
            ->columns([
                TextColumn::make('id')->alignEnd(),
                GridColumn::make('name')->required()->maxLength(40),
                GridColumn::make('sku')->rules(fn (Product $record): array => [Rule::unique('products', 'sku')->ignore($record)]),
                GridColumn::make('price')->number(min: 0),
                GridColumn::make('stock')->integer(min: 0, max: 1000),
                GridColumn::make('category')->select(['toys' => 'Toys', 'books' => 'Books', '7' => 'Seven']),
                GridColumn::make('released_on')->date('d.m.Y'),
                GridColumn::make('available')->boolean(),
                GridColumn::make('locked')->editable(false)->boolean(),
                GridColumn::make('cost')->number(min: 0)->visible(fn (): bool => static::$showCost),
            ])
            ->filters([TernaryFilter::make('available')])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/')];
    }
}
