<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Shelves;

use Filament\Resources\Resource;
use Workbench\App\Filament\Resources\Shelves\Pages\EditShelf;
use Workbench\App\Filament\Resources\Shelves\Pages\ViewShelf;
use Workbench\App\Filament\Resources\Shelves\RelationManagers\ProductsRelationManager;
use Workbench\App\Models\Shelf;

/**
 * A parent resource whose products are edited in a relation manager.
 */
class ShelfResource extends Resource
{
    protected static ?string $model = Shelf::class;

    public static function getRelations(): array
    {
        return [ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewShelf::route('/{record}'),
            'edit' => EditShelf::route('/{record}/edit'),
        ];
    }
}
