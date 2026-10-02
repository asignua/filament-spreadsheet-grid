<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpreadsheetGridServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'asignua/filament-spreadsheet-grid';

    public const string STYLESHEET = 'filament-spreadsheet-grid';

    public const string COMPONENT = 'spreadsheet-grid';

    /** The short name: it is the view, translation and config namespace (`filament-` dropped). */
    public static string $name = 'spreadsheet-grid';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('spreadsheet-grid')
            ->hasViews()
            ->hasTranslations();
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            AlpineComponent::make(self::COMPONENT, __DIR__.'/../resources/dist/spreadsheet-grid.js'),
            Css::make(self::STYLESHEET, __DIR__.'/../resources/dist/filament-spreadsheet-grid.css')->loadedOnRequest(),
        ], self::PACKAGE);

        // After the panel's theme, not before it as auto-loaded plugin assets are: both
        // compile the same utilities, and with equal specificity the later file wins.
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => '<link rel="stylesheet" href="'.e(FilamentAsset::getStyleHref(self::STYLESHEET, self::PACKAGE)).'" />',
        );
    }
}
