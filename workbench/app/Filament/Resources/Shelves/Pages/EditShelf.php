<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Shelves\Pages;

use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Shelves\ShelfResource;

class EditShelf extends EditRecord
{
    protected static string $resource = ShelfResource::class;
}
