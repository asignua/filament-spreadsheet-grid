<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Livewire\Livewire;
use Workbench\App\Filament\Resources\Products\Pages\ListAtomicProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;

class RenderTest extends TestCase
{
    public function test_each_grid_column_renders_a_cell_with_key_field_and_value(): void
    {
        $a = $this->product('A', ['price' => '12.50', 'available' => false, 'category' => 'books']);

        Livewire::test(ListProducts::class)
            ->assertSeeHtml('data-sg-key="'.$a->id.'"')
            ->assertSeeHtml('data-sg-field="price"')
            ->assertSeeHtml('data-sg-value="12.50"')
            ->assertSeeHtml('data-sg-value="books"')
            ->assertSee('Books')
            ->assertSee('✗');
    }

    public function test_cells_of_records_the_user_may_not_update_are_read_only(): void
    {
        $this->product('OPEN');
        $locked = $this->product('LOCKED', ['locked' => true]);

        $html = Livewire::test(ListProducts::class)->html();

        // Every cell of the locked row is read-only (8); in the open row only the
        // `editable(false)` column is.
        $this->assertSame(8, substr_count($html, 'data-sg-key="'.$locked->id.'"'));
        $this->assertSame(9, substr_count($html, 'data-sg-readonly'));
    }

    public function test_a_column_marked_not_editable_is_read_only_for_everyone(): void
    {
        $this->product('OPEN');

        $html = Livewire::test(ListProducts::class)->html();

        $this->assertSame(1, substr_count($html, 'data-sg-readonly'));
    }

    public function test_the_table_filters_and_search_still_decide_which_rows_are_shown(): void
    {
        $this->product('IN');
        $out = $this->product('OUT', ['available' => false]);

        Livewire::test(ListProducts::class)
            ->set('tableFilters.available.value', '1')
            ->assertDontSeeHtml('data-sg-key="'.$out->id.'"');
    }

    /**
     * The config the toolbar hands to the Alpine component, decoded from the page.
     *
     * @return array<string, mixed>
     */
    private function clientConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/spreadsheetGrid\\(JSON\\.parse\\('(.*?)'\\), \\\$wire\\)/s", $html, $match), 'toolbar is missing');

        /** @var array<string, mixed> */
        return json_decode(str_replace('\\u0022', '"', $match[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_toolbar_carries_the_client_config(): void
    {
        $this->product('A');

        $html = Livewire::test(ListProducts::class)->html();
        $config = $this->clientConfig($html);

        $this->assertTrue(str_contains($html, 'x-load-src'), 'component is not lazy-loaded');
        $this->assertTrue(str_contains($html, 'wire:ignore'), 'toolbar is not wire:ignore');
        $this->assertTrue(str_contains($html, __('spreadsheet-grid::messages.save_all')), 'button label is missing');

        $this->assertSame(['name', 'sku', 'price', 'stock', 'category', 'released_on', 'available', 'locked'], $config['order']);
        $this->assertSame('number', $config['columns']['price']['type']);
        $this->assertSame(0, $config['columns']['price']['min']);
        $this->assertSame(1000, $config['columns']['stock']['max']);
        $this->assertTrue($config['columns']['name']['required']);
        $this->assertSame([['value' => 'toys', 'label' => 'Toys'], ['value' => 'books', 'label' => 'Books'], ['value' => '7', 'label' => 'Seven']], $config['columns']['category']['options']);
        $this->assertSame('d.m.Y', $config['columns']['released_on']['dateFormat']);
        $this->assertSame(__('spreadsheet-grid::messages.client_required'), $config['messages']['required']);
    }

    public function test_autosave_is_part_of_the_client_config(): void
    {
        $this->product('A');

        $plain = $this->clientConfig(Livewire::test(ListProducts::class)->html());
        $auto = $this->clientConfig(Livewire::test(ListAtomicProducts::class)->html());

        $this->assertFalse($plain['autosave']);
        $this->assertTrue($auto['autosave']);
    }

    public function test_numeric_columns_are_right_aligned(): void
    {
        $this->product('A');

        $html = Livewire::test(ListProducts::class)->html();

        $this->assertStringContainsString('fi-align-end', $html);
    }
}
