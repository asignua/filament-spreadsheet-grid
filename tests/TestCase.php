<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGridServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Filament\Resources\ScopedProducts\Pages\ListScopedProducts;
use Workbench\App\Models\Product;
use Workbench\App\Models\User;
use Workbench\App\Policies\ProductPolicy;
use Workbench\App\Providers\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Product::class, ProductPolicy::class);
        ListScopedProducts::$calls = [];
        ProductResource::$showCost = false;
        ProductResource::$categoryPerRecord = false;

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            SpreadsheetGridServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        // A second database, for models that live on a connection of their own.
        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    /**
     * Saves through the real Livewire call path, like the browser's `$wire.saveSpreadsheetGrid()`:
     * hydration, the method call, the re-render and the returned value.
     *
     * @param class-string                            $component
     * @param array<mixed>                            $changes
     * @param array<array-key, array<string, string>> $originals
     * @param array<string, mixed>                    $params    mount parameters
     *
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>, values: array<string, array<string, string>>}
     */
    protected function callSave(string $component, array $changes, array $originals = [], bool $autosave = false, array $params = [], ?Testable &$testable = null): array
    {
        $testable = Livewire::test($component, $params)->call('saveSpreadsheetGrid', $changes, $originals, $autosave);

        /** @var array{saved: list<string>, errors: array<string, array<string, list<string>>>, values: array<string, array<string, string>>} */
        return $testable->effects['returns'][0];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function product(string $sku, array $attributes = []): Product
    {
        $product = new Product;
        $product->forceFill([
            'name' => 'Product '.$sku,
            'sku' => $sku,
            'price' => '10.00',
            'stock' => 5,
            'category' => 'toys',
            'available' => true,
            ...$attributes,
        ]);
        $product->save();

        return $product;
    }
}
