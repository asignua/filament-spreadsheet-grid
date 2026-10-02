<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\Support\GridResult;
use Asignua\FilamentSpreadsheetGrid\Support\GridSaver;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Workbench\App\Filament\Resources\Products\Pages\ListAtomicProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Gadget;
use Workbench\App\Models\Product;

/**
 * What one save request does around the writes: notifications, conflicts, limits, connections.
 */
class SaveRequestTest extends TestCase
{
    public function test_a_manual_save_returns_the_result_and_notifies(): void
    {
        $a = $this->product('A');

        $result = $this->callSave(ListProducts::class, [(string) $a->id => ['name' => 'Alpha']]);

        $this->assertSame([(string) $a->id], $result['saved']);
        Notification::assertNotified(trans_choice('spreadsheet-grid::messages.saved', 1, ['count' => 1]));
    }

    public function test_an_autosave_that_succeeds_sends_no_toast(): void
    {
        $a = $this->product('A');

        $result = $this->callSave(ListAtomicProducts::class, [(string) $a->id => ['name' => 'Alpha']], autosave: true);

        $this->assertSame([(string) $a->id], $result['saved']);
        Notification::assertNotNotified();
    }

    public function test_an_autosave_that_fails_still_reports_it(): void
    {
        $a = $this->product('A');

        $this->callSave(ListAtomicProducts::class, [(string) $a->id => ['price' => 'abc']], autosave: true);

        Notification::assertNotified(trans_choice('spreadsheet-grid::messages.failed', 1, ['count' => 1]));
    }

    public function test_a_cell_changed_by_someone_else_since_loading_is_not_overwritten(): void
    {
        $a = $this->product('A', ['price' => '12.00']);
        $b = $this->product('B', ['price' => '10.00']);

        // Both rows were loaded at 10.00; meanwhile somebody saved A at 12.00.
        $result = $this->callSave(
            ListProducts::class,
            [(string) $a->id => ['price' => '11'], (string) $b->id => ['price' => '11']],
            [(string) $a->id => ['price' => '10.00'], (string) $b->id => ['price' => '10.00']],
        );

        $this->assertSame([(string) $b->id], $result['saved']);
        $this->assertArrayHasKey('price', $result['errors'][(string) $a->id]);
        $this->assertSame('12.00', $a->fresh()?->price);
        $this->assertSame('11.00', $b->fresh()?->price);
    }

    public function test_conflict_detection_can_be_turned_off(): void
    {
        $a = $this->product('A', ['price' => '12.00']);

        $saver = new GridSaver(
            Product::query(),
            ['price' => GridColumn::make('price')->number()],
            SpreadsheetGrid::make()->detectConflicts(false),
        );

        $result = $saver->save([(string) $a->id => ['price' => '11']], [(string) $a->id => ['price' => '10.00']]);

        $this->assertSame([(string) $a->id], $result->saved);
        $this->assertSame('11.00', $a->fresh()?->price);
    }

    public function test_the_cell_limit_has_its_own_message(): void
    {
        $a = $this->product('A');

        $saver = new GridSaver(
            Product::query(),
            ['name' => GridColumn::make('name'), 'sku' => GridColumn::make('sku')],
            SpreadsheetGrid::make()->maxCells(1),
        );

        $result = $saver->save([(string) $a->id => ['name' => 'x', 'sku' => 'y']]);

        $this->assertSame([__('spreadsheet-grid::messages.too_many_cells', ['max' => 1])], $result->errors[(string) $a->id][GridResult::ROW]);
    }

    public function test_an_integer_beyond_the_php_range_is_rejected_not_saturated(): void
    {
        [, $errors] = GridColumn::make('stock')->integer()->prepare('99999999999999999999');

        $this->assertNotSame([], $errors);
    }

    public function test_the_batch_transaction_runs_on_the_models_own_connection(): void
    {
        Schema::connection('secondary')->create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $first = new Gadget;
        $first->forceFill(['name' => 'one'])->save();
        $second = new Gadget;
        $second->forceFill(['name' => 'two'])->save();

        $saver = new GridSaver(
            Gadget::query(),
            ['name' => GridColumn::make('name')],
            SpreadsheetGrid::make()->atomic()->authorizeUsing(fn (): bool => true)->saveUsing(function (Model $record, array $changes): void {
                if ($changes['name'] === 'boom') {
                    throw ValidationException::withMessages(['name' => 'Refused late.']);
                }

                $record->setAttribute('name', $changes['name']);
                $record->save();
            }),
        );

        $result = $saver->save([
            (string) $first->id => ['name' => 'changed'],
            (string) $second->id => ['name' => 'boom'],
        ]);

        // "Nothing written" must be true: the first row is rolled back with the batch.
        $this->assertSame([], $result->saved);
        $this->assertSame('one', $first->fresh()?->name);
    }
}
