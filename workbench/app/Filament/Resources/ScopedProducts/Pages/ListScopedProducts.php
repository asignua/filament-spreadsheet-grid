<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\ScopedProducts\Pages;

use Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Workbench\App\Filament\Resources\ScopedProducts\ScopedProductResource;

class ListScopedProducts extends ListRecords
{
    use InteractsWithSpreadsheetGrid;

    /** @var list<array{id: mixed, changes: array<string, mixed>}> */
    public static array $calls = [];

    protected static string $resource = ScopedProductResource::class;

    protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
    {
        return $grid->saveUsing(function (Model $record, array $changes): void {
            self::$calls[] = ['id' => $record->getKey(), 'changes' => $changes];

            if (($changes['name'] ?? null) === 'Forbidden name') {
                throw ValidationException::withMessages(['name' => 'The repository refused this name.']);
            }

            // A repository would be called here; only attributes that really exist are written.
            if (array_key_exists('name', $changes)) {
                $record->setAttribute('name', $changes['name']);
            }

            if (array_key_exists('price', $changes)) {
                $record->setAttribute('price', $changes['price']);
            }

            $record->save();
        });
    }
}
