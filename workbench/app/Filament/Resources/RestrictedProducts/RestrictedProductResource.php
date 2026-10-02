<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\RestrictedProducts;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Filament\Resources\RestrictedProducts\Pages\ListRestrictedProducts;
use Workbench\App\Models\Product;

/**
 * Restricts editing the common Filament way: a `canEdit()` override, no policy involved.
 */
class RestrictedProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'restricted-products';

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Product && $record->sku !== 'VIP';
    }

    public static function table(Table $table): Table
    {
        return ProductResource::table($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListRestrictedProducts::route('/')];
    }
}
