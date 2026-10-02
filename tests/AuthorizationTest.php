<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use Workbench\App\Filament\Resources\Products\Pages\ListProducts;
use Workbench\App\Filament\Resources\Products\Pages\ListToggleProducts;
use Workbench\App\Filament\Resources\Products\ProductResource;
use Workbench\App\Filament\Resources\RestrictedProducts\Pages\ListRestrictedProducts;
use Workbench\App\Filament\Resources\Shelves\Pages\EditShelf;
use Workbench\App\Filament\Resources\Shelves\Pages\ViewShelf;
use Workbench\App\Filament\Resources\Shelves\RelationManagers\ProductsRelationManager;
use Workbench\App\Models\Shelf;
use Workbench\App\Models\User;

/**
 * The grid must refuse exactly what Filament refuses: hidden columns, a resource's canEdit(),
 * a read-only relation manager, strict authorization.
 */
class AuthorizationTest extends TestCase
{
    public function test_a_hidden_column_is_refused_and_not_sent_to_the_browser(): void
    {
        $a = $this->product('A', ['cost' => '3.00']);

        $result = $this->callSave(ListProducts::class, [(string) $a->id => ['cost' => '0']]);

        $this->assertSame([], $result['saved']);
        $this->assertArrayHasKey('cost', $result['errors'][(string) $a->id]);
        $this->assertSame('3.00', $a->fresh()?->cost);

        /** @var ListProducts $list */
        $list = Livewire::test(ListProducts::class)->instance();
        $this->assertArrayNotHasKey('cost', $list->spreadsheetGridClientConfig()['columns']);
    }

    public function test_a_visible_conditional_column_is_editable(): void
    {
        ProductResource::$showCost = true;
        $a = $this->product('A', ['cost' => '3.00']);

        $result = $this->callSave(ListProducts::class, [(string) $a->id => ['cost' => '4,5']]);

        $this->assertSame([(string) $a->id], $result['saved']);
        $this->assertSame('4.50', $a->fresh()?->cost);
    }

    public function test_a_resource_can_edit_override_is_honoured_without_a_policy_saying_no(): void
    {
        $open = $this->product('OPEN');
        $vip = $this->product('VIP');

        $result = $this->callSave(ListRestrictedProducts::class, [
            (string) $open->id => ['name' => 'Changed'],
            (string) $vip->id => ['name' => 'Hacked'],
        ]);

        $this->assertSame([(string) $open->id], $result['saved']);
        $this->assertSame([__('spreadsheet-grid::messages.row_forbidden')], $result['errors'][(string) $vip->id]['*']);
        $this->assertSame('Product VIP', $vip->fresh()?->name);
    }

    public function test_a_relation_manager_on_a_view_page_is_read_only(): void
    {
        $shelf = $this->shelf();
        $a = $this->product('A', ['shelf_id' => $shelf->id]);

        $result = $this->callSave(ProductsRelationManager::class, [(string) $a->id => ['name' => 'Hacked']], params: [
            'ownerRecord' => $shelf,
            'pageClass' => ViewShelf::class,
        ]);

        $this->assertSame([], $result['saved']);
        $this->assertArrayHasKey('*', $result['errors'][(string) $a->id]);
        $this->assertSame('Product A', $a->fresh()?->name);
    }

    public function test_a_relation_manager_on_an_edit_page_saves_and_still_asks_the_policy(): void
    {
        $shelf = $this->shelf();
        $a = $this->product('A', ['shelf_id' => $shelf->id]);
        $locked = $this->product('L', ['shelf_id' => $shelf->id, 'locked' => true]);

        $result = $this->callSave(ProductsRelationManager::class, [
            (string) $a->id => ['name' => 'Renamed'],
            (string) $locked->id => ['name' => 'Hacked'],
        ], params: ['ownerRecord' => $shelf, 'pageClass' => EditShelf::class]);

        $this->assertSame([(string) $a->id], $result['saved']);
        $this->assertArrayHasKey('*', $result['errors'][(string) $locked->id]);
        $this->assertSame('Renamed', $a->fresh()?->name);
    }

    public function test_strict_authorization_does_not_treat_a_missing_policy_as_allow(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(SpreadsheetGrid::make()->canEdit($user));

        Filament::getCurrentOrDefaultPanel()?->strictAuthorization();

        // For the logged-in user the grid asks exactly like Filament, which refuses loudly.
        try {
            SpreadsheetGrid::make()->canEdit($user);
            $this->fail('Strict authorization should refuse a model without a policy.');
        } catch (LogicException) {
        }

        // For any other user (the saver used outside a panel request) it is a plain no.
        $this->assertFalse(SpreadsheetGrid::make()->canEdit($user, User::factory()->create()));
    }

    public function test_the_mode_property_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ListToggleProducts::class)->set('spreadsheetGridActive', true);
    }

    public function test_the_toggle_method_still_switches_the_mode(): void
    {
        $a = $this->product('A');

        $component = Livewire::test(ListToggleProducts::class)->call('toggleSpreadsheetGrid', true);

        $this->assertTrue($component->get('spreadsheetGridActive'));
        $this->assertSame([(string) $a->id], $component->call('saveSpreadsheetGrid', [(string) $a->id => ['name' => 'Fine']])->effects['returns'][0]['saved']);
    }

    private function shelf(): Shelf
    {
        $shelf = new Shelf;
        $shelf->forceFill(['name' => 'Top']);
        $shelf->save();

        return $shelf;
    }
}
