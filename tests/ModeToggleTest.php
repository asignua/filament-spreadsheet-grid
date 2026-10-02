<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Livewire\Livewire;
use Workbench\App\Filament\Resources\Products\Pages\ListPersistedToggleProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListToggleProducts;

class ModeToggleTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function clientConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/spreadsheetGrid\\(JSON\\.parse\\('(.*?)'\\), \\\$wire\\)/s", $html, $match));

        /** @var array<string, mixed> */
        return json_decode(str_replace('\\u0022', '"', $match[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_without_toggleable_the_grid_is_always_active(): void
    {
        $this->product('A');

        $page = Livewire::test(ListProducts::class);

        $this->assertStringContainsString('data-sg-cell', $page->html());
        $this->assertFalse($this->clientConfig($page->html())['toggleable']);
        $this->assertTrue($this->clientConfig($page->html())['active']);

        // The switch is inert there.
        $page->call('toggleSpreadsheetGrid', false);
        $this->assertStringContainsString('data-sg-cell', $page->html());
    }

    public function test_a_toggleable_grid_starts_as_a_plain_read_only_table(): void
    {
        $this->product('A', ['price' => '12.50', 'category' => 'books']);

        $html = Livewire::test(ListToggleProducts::class)->html();

        $this->assertStringNotContainsString('data-sg-cell', $html);
        $this->assertStringContainsString('sg-plain', $html);
        $this->assertStringContainsString('12.50', $html);
        $this->assertStringContainsString('Books', $html);

        $config = $this->clientConfig($html);
        $this->assertTrue($config['toggleable']);
        $this->assertFalse($config['active']);
        $this->assertStringContainsString(__('spreadsheet-grid::messages.edit_as_spreadsheet'), $html);
    }

    public function test_turning_the_mode_on_renders_grid_cells_and_off_removes_them(): void
    {
        $this->product('A');

        $page = Livewire::test(ListToggleProducts::class)->call('toggleSpreadsheetGrid', true);

        $this->assertStringContainsString('data-sg-cell', $page->html());
        $this->assertStringNotContainsString('sg-plain', $page->html());

        $page->call('toggleSpreadsheetGrid', false);

        $this->assertStringNotContainsString('data-sg-cell', $page->html());
    }

    public function test_saving_is_refused_while_the_mode_is_off(): void
    {
        $a = $this->product('A');

        /** @var ListToggleProducts $list */
        $list = Livewire::test(ListToggleProducts::class)->instance();

        $result = $list->saveSpreadsheetGrid([(string) $a->id => ['name' => 'Sneaky']]);

        $this->assertSame([], $result['saved']);
        $this->assertSame([__('spreadsheet-grid::messages.mode_off')], $result['errors'][(string) $a->id]['*']);
        $this->assertSame('Product A', $a->fresh()?->name);
    }

    public function test_saving_works_once_the_mode_is_on(): void
    {
        $a = $this->product('A');

        $page = Livewire::test(ListToggleProducts::class)->call('toggleSpreadsheetGrid', true);

        /** @var ListToggleProducts $list */
        $list = $page->instance();

        $this->assertSame([(string) $a->id], $list->saveSpreadsheetGrid([(string) $a->id => ['name' => 'Fine']])['saved']);
    }

    public function test_the_default_can_be_on_and_the_choice_is_remembered_in_the_session(): void
    {
        $this->product('A');

        $first = Livewire::test(ListPersistedToggleProducts::class);
        $this->assertStringContainsString('data-sg-cell', $first->html());

        $first->call('toggleSpreadsheetGrid', false);

        // A fresh component (another visit) picks the remembered choice up.
        $this->assertStringNotContainsString('data-sg-cell', Livewire::test(ListPersistedToggleProducts::class)->html());
    }

    public function test_the_toolbar_has_the_exit_texts_for_the_client(): void
    {
        $this->product('A');

        $html = Livewire::test(ListToggleProducts::class)->html();
        $config = $this->clientConfig($html);

        $this->assertSame(__('spreadsheet-grid::messages.confirm_exit'), $config['messages']['confirmExit']);
        $this->assertStringContainsString(__('spreadsheet-grid::messages.discard_and_exit'), $html);
        $this->assertStringContainsString(__('spreadsheet-grid::messages.done'), $html);
    }
}
