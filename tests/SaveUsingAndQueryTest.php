<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\Support\GridSaver;
use Livewire\Livewire;
use LogicException;
use Workbench\App\Filament\Resources\ScopedProducts\Pages\ListScopedProducts;
use Workbench\App\Models\Product;

class SaveUsingAndQueryTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $changes
     *
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>, values: array<string, array<string, string>>}
     */
    private function save(array $changes): array
    {
        return $this->callSave(ListScopedProducts::class, $changes);
    }

    public function test_the_save_using_callback_receives_the_record_and_validated_typed_changes(): void
    {
        $a = $this->product('A');

        $result = $this->save([(string) $a->id => ['name' => 'Via repository', 'price' => '9,5']]);

        $this->assertSame([(string) $a->id], $result['saved']);
        $this->assertCount(1, ListScopedProducts::$calls);
        $this->assertSame($a->id, ListScopedProducts::$calls[0]['id']);
        $this->assertSame(['name' => 'Via repository', 'price' => '9.5'], ListScopedProducts::$calls[0]['changes']);
        $this->assertSame('Via repository', $a->fresh()?->name);
    }

    public function test_a_column_that_is_not_an_attribute_reaches_the_callback_untouched(): void
    {
        $a = $this->product('A');

        $result = $this->save([(string) $a->id => ['label.upper' => 'HELLO']]);

        $this->assertSame([(string) $a->id], $result['saved']);
        $this->assertSame(['label.upper' => 'HELLO'], ListScopedProducts::$calls[0]['changes']);
    }

    public function test_a_validation_exception_from_the_callback_becomes_a_cell_error(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');

        $result = $this->save([
            (string) $a->id => ['name' => 'Forbidden name'],
            (string) $b->id => ['name' => 'Allowed'],
        ]);

        $this->assertSame([(string) $b->id], $result['saved']);
        $this->assertSame(['The repository refused this name.'], $result['errors'][(string) $a->id]['name']);
        $this->assertSame('Product A', $a->fresh()?->name);
    }

    public function test_records_outside_the_table_query_cannot_be_edited(): void
    {
        $visible = $this->product('V');
        $archived = $this->product('ARCH', ['archived' => true]);

        $result = $this->save([
            (string) $visible->id => ['name' => 'Ok'],
            (string) $archived->id => ['name' => 'Sneaky'],
            '99999' => ['name' => 'Ghost'],
        ]);

        $this->assertSame([(string) $visible->id], $result['saved']);
        $this->assertArrayHasKey('*', $result['errors'][(string) $archived->id]);
        $this->assertArrayHasKey('*', $result['errors']['99999']);
        $this->assertSame('Product ARCH', $archived->fresh()?->name);
        $this->assertCount(1, ListScopedProducts::$calls);
    }

    public function test_the_default_writer_refuses_a_dotted_column_instead_of_guessing(): void
    {
        $a = $this->product('A');

        $saver = new GridSaver(
            Product::query(),
            ['label.upper' => GridColumn::make('label.upper')],
            SpreadsheetGrid::make(),
        );

        $this->expectException(LogicException::class);

        $saver->save([(string) $a->id => ['label.upper' => 'x']]);
    }

    public function test_authorize_using_replaces_the_policy(): void
    {
        $locked = $this->product('L', ['locked' => true]);

        $saver = new GridSaver(
            Product::query(),
            ['name' => GridColumn::make('name')],
            SpreadsheetGrid::make()->authorizeUsing(fn (): bool => true),
        );

        $result = $saver->save([(string) $locked->id => ['name' => 'Allowed by closure']]);

        $this->assertSame([(string) $locked->id], $result->saved);
    }

    public function test_a_filtered_table_still_saves_rows_the_filter_hides(): void
    {
        // The edit set is keyed by record, not by what is on the page: a row can leave the
        // filtered view (page change, filter change) between editing and saving.
        $hidden = $this->product('H', ['available' => false]);

        /** @var \Workbench\App\Filament\Resources\Products\Pages\ListProducts $list */
        $list = Livewire::test(\Workbench\App\Filament\Resources\Products\Pages\ListProducts::class)
            ->set('tableFilters.available.value', '1')
            ->instance();

        $result = $list->saveSpreadsheetGrid([(string) $hidden->id => ['name' => 'Still saved']]);

        $this->assertSame([(string) $hidden->id], $result['saved']);
    }

    public function test_a_row_revealed_by_a_filters_base_query_can_be_saved(): void
    {
        $archived = $this->product('ARC', ['archived' => true]);
        $changes = [(string) $archived->id => ['name' => 'Edited']];

        $hidden = $this->save($changes);

        $this->assertSame([], $hidden['saved']);

        Livewire::test(ListScopedProducts::class)
            ->set('tableFilters.with_archived.isActive', true)
            ->call('saveSpreadsheetGrid', $changes, [], false);

        $this->assertSame('Edited', $archived->fresh()?->name);
    }
}
