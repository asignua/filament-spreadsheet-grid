<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\Support\GridSaver;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Filament\Resources\Products\Pages\ListAtomicProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Product;

/**
 * A save answers with what each sent cell holds now, as the grid renders it: the client
 * takes that as the original of a cell edited again during the save, so it must be the
 * stored form, not the text that was sent.
 */
class StoredValuesTest extends TestCase
{
    public function test_a_save_returns_the_stored_form_of_each_sent_cell(): void
    {
        $a = $this->product('A');

        $result = $this->callSave(ListProducts::class, [
            (string) $a->id => ['price' => '10', 'stock' => '5.0', 'released_on' => '2026-10-02', 'available' => 'yes', 'name' => 'Alpha'],
        ]);

        $this->assertSame([(string) $a->id], $result['saved']);
        $this->assertSame(
            ['price' => '10.00', 'stock' => '5', 'released_on' => '2026-10-02', 'available' => '1', 'name' => 'Alpha'],
            $result['values'][(string) $a->id],
        );
    }

    public function test_the_stored_value_is_also_what_the_conflict_check_accepts_next(): void
    {
        $a = $this->product('A');

        $first = $this->callSave(ListProducts::class, [(string) $a->id => ['price' => '10']]);
        $original = $first['values'][(string) $a->id]['price'];

        $next = $this->callSave(
            ListAtomicProducts::class,
            [(string) $a->id => ['price' => '11']],
            [(string) $a->id => ['price' => $original]],
        );

        $this->assertSame([(string) $a->id], $next['saved']);
        $this->assertSame([], $next['errors']);
        $this->assertSame('11.00', $a->fresh()?->price);
    }

    public function test_rows_that_are_not_saved_return_no_values(): void
    {
        $a = $this->product('A');

        $result = $this->callSave(ListProducts::class, [(string) $a->id => ['name' => '']]);

        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['values']);
    }

    public function test_empty_as_save_using_and_mutators_are_read_back(): void
    {
        $model = new class extends Product
        {
            protected $table = 'products';

            /**
             * @return Attribute<string, string>
             */
            protected function sku(): Attribute
            {
                return Attribute::make(set: fn (string $value): string => strtoupper($value));
            }
        };

        $model->forceFill(['name' => 'Product A', 'sku' => 'A', 'price' => '1.00', 'stock' => 3, 'category' => 'toys', 'available' => true])->save();

        $saver = new GridSaver(
            $model->newQuery(),
            [
                'name' => GridColumn::make('name'),
                'sku' => GridColumn::make('sku'),
                'stock' => GridColumn::make('stock')->integer()->emptyAs(0),
            ],
            SpreadsheetGrid::make()->authorizeUsing(fn (): bool => true)->saveUsing(function (Model $record, array $changes): void {
                foreach ($changes as $field => $value) {
                    // A repository that trims and title-cases the name.
                    $record->setAttribute($field, $field === 'name' ? ucwords(trim((string) $value)) : $value);
                }

                $record->save();
            }),
        );

        $key = (string) $model->getKey();
        $result = $saver->save([$key => ['name' => '  new name ', 'sku' => 'abc-1', 'stock' => '']]);

        $this->assertSame([$key], $result->saved);
        $this->assertSame(['name' => 'New Name', 'sku' => 'ABC-1', 'stock' => '0'], $result->values[$key]);
    }
}
