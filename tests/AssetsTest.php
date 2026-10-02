<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGridServiceProvider;
use Filament\Support\Facades\FilamentAsset;

class AssetsTest extends TestCase
{
    public function test_the_compiled_assets_ship_with_the_package(): void
    {
        $this->assertFileExists(__DIR__.'/../resources/dist/spreadsheet-grid.js');
        $this->assertFileExists(__DIR__.'/../resources/dist/filament-spreadsheet-grid.css');
    }

    public function test_the_alpine_component_and_the_stylesheet_are_registered(): void
    {
        $this->assertStringContainsString(
            'spreadsheet-grid.js',
            FilamentAsset::getAlpineComponentSrc(SpreadsheetGridServiceProvider::COMPONENT, SpreadsheetGridServiceProvider::PACKAGE),
        );
        $this->assertStringContainsString(
            'filament-spreadsheet-grid.css',
            FilamentAsset::getStyleHref(SpreadsheetGridServiceProvider::STYLESHEET, SpreadsheetGridServiceProvider::PACKAGE),
        );
    }

    public function test_the_panel_links_the_stylesheet_after_its_theme(): void
    {
        $this->app->forgetInstance('auth');
        auth()->logout();

        $this->get('/admin/login')->assertSee('filament-spreadsheet-grid.css', false);
    }

    public function test_end_aligned_columns_align_the_cell_and_the_editor_text(): void
    {
        // The <td> gets text-align via .fi-align-end, but the cell is a block inside a flex wrapper, so the
        // number columns (alignEnd) showed left-aligned values under right-aligned headers in a real browser.
        $css = (string) file_get_contents(__DIR__.'/../resources/dist/filament-spreadsheet-grid.css');

        $this->assertMatchesRegularExpression('/\.fi-align-end>\*>\.sg-cell[^{]*\{[^}]*text-align:\s*(end|right)/', $css);
        $this->assertMatchesRegularExpression('/\.fi-align-end>\*>\.sg-plain/', $css);
    }
}
