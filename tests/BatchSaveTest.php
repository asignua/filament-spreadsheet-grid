<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Livewire\Livewire;
use Workbench\App\Filament\Resources\Products\Pages\ListAtomicProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Models\Product;

class BatchSaveTest extends TestCase
{
    /**
     * @param class-string<ListProducts>          $page
     * @param array<string, array<string, mixed>> $changes
     *
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>}
     */
    private function save(array $changes, string $page = ListProducts::class): array
    {
        /** @var ListProducts $list */
        $list = Livewire::test($page)->instance();

        return $list->saveSpreadsheetGrid($changes);
    }

    public function test_it_saves_many_rows_and_cells_in_one_request_with_typed_values(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');

        $result = $this->save([
            (string) $a->id => [
                'name' => 'Alpha',
                'price' => '1 250,5',
                'stock' => '12',
                'category' => '7',
                'available' => '0',
                'released_on' => '2026-10-02',
            ],
            (string) $b->id => ['name' => 'Beta', 'stock' => ''],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $result['saved']);

        $a->refresh();
        $this->assertSame('Alpha', $a->name);
        $this->assertSame('1250.50', $a->price);
        $this->assertSame(12, $a->stock);
        $this->assertSame('7', $a->category);
        $this->assertFalse($a->available);
        $this->assertSame('2026-10-02', $a->released_on?->toDateString());

        $b->refresh();
        $this->assertSame('Beta', $b->name);
        $this->assertNull($b->stock);
    }

    public function test_validation_errors_are_reported_per_row_and_cell_and_valid_rows_still_save(): void
    {
        $good = $this->product('GOOD');
        $bad = $this->product('BAD');
        $other = $this->product('TAKEN');

        $result = $this->save([
            (string) $good->id => ['name' => 'Fine'],
            (string) $bad->id => [
                'name' => '',
                'price' => 'abc',
                'stock' => '5000',
                'category' => 'cars',
                'released_on' => '31.02.2026',
                'sku' => $other->sku,
            ],
        ]);

        $this->assertSame([(string) $good->id], $result['saved']);

        $errors = $result['errors'][(string) $bad->id];
        $this->assertEqualsCanonicalizing(['name', 'price', 'stock', 'category', 'released_on', 'sku'], array_keys($errors));
        $this->assertArrayNotHasKey((string) $good->id, $result['errors']);

        // Nothing of the bad row was written, not even its valid cells.
        $this->assertSame('Product BAD', $bad->fresh()?->name);
        $this->assertSame('Fine', $good->fresh()?->name);
    }

    public function test_unique_rule_ignores_the_record_itself(): void
    {
        $a = $this->product('SAME');

        $result = $this->save([(string) $a->id => ['sku' => 'SAME', 'name' => 'Renamed']]);

        $this->assertSame([(string) $a->id], $result['saved']);
    }

    public function test_a_policy_that_denies_update_blocks_the_row(): void
    {
        $open = $this->product('OPEN');
        $locked = $this->product('LOCKED', ['locked' => true]);

        $result = $this->save([
            (string) $open->id => ['name' => 'Changed'],
            (string) $locked->id => ['name' => 'Hacked'],
        ]);

        $this->assertSame([(string) $open->id], $result['saved']);
        $this->assertArrayHasKey('*', $result['errors'][(string) $locked->id]);
        $this->assertSame('Product LOCKED', $locked->fresh()?->name);
    }

    public function test_columns_that_are_not_editable_or_not_grid_columns_are_refused(): void
    {
        $a = $this->product('A');

        $result = $this->save([(string) $a->id => ['locked' => '1', 'id' => '99', 'name' => 'ok']]);

        $this->assertSame([], $result['saved']);
        $this->assertEqualsCanonicalizing(['locked', 'id'], array_keys($result['errors'][(string) $a->id]));
        $this->assertFalse($a->fresh()?->locked);
        $this->assertSame($a->id, $a->fresh()?->id);
    }

    public function test_atomic_mode_writes_nothing_when_any_row_fails(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');

        $result = $this->save([
            (string) $a->id => ['name' => 'Alpha'],
            (string) $b->id => ['price' => 'abc'],
        ], ListAtomicProducts::class);

        $this->assertSame([], $result['saved']);
        $this->assertArrayHasKey('price', $result['errors'][(string) $b->id]);
        $this->assertArrayHasKey('*', $result['errors'][(string) $a->id]);
        $this->assertSame('Product A', $a->fresh()?->name);
    }

    public function test_atomic_mode_saves_everything_when_all_rows_are_valid(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');

        $result = $this->save([
            (string) $a->id => ['name' => 'Alpha'],
            (string) $b->id => ['name' => 'Beta'],
        ], ListAtomicProducts::class);

        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $result['saved']);
        $this->assertSame('Beta', $b->fresh()?->name);
    }

    public function test_the_batch_is_capped(): void
    {
        config(['spreadsheet-grid.max_rows' => 2]);

        $rows = [];

        foreach (['A', 'B', 'C'] as $sku) {
            $rows[(string) $this->product($sku)->id] = ['name' => 'x'];
        }

        $result = $this->save($rows);

        $this->assertSame([], $result['saved']);
        $this->assertCount(3, $result['errors']);
        $this->assertSame('Product A', Product::query()->where('sku', 'A')->value('name'));
    }

    public function test_a_malformed_payload_is_ignored_not_trusted(): void
    {
        $a = $this->product('A');

        $result = $this->save([
            (string) $a->id => ['name' => ['nested' => 'x']],
            'junk' => 'string',
        ]);

        $this->assertSame([], $result['saved']);
    }
}
