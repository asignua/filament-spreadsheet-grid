<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Concerns;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\Support\GridResult;
use Asignua\FilamentSpreadsheetGrid\Support\GridSaver;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * Add to a Livewire component that has a Filament table (a resource List page, a relation
 * manager, a table widget) to let its {@see GridColumn}s be edited like a spreadsheet:
 *
 *     class ListProducts extends ListRecords
 *     {
 *         use InteractsWithSpreadsheetGrid;
 *     }
 *
 * and in the table: `->header(SpreadsheetGrid::toolbar())` plus `GridColumn`s.
 */
trait InteractsWithSpreadsheetGrid
{
    protected ?SpreadsheetGrid $spreadsheetGridInstance = null;

    /**
     * The user's choice when the grid is `toggleable()`; null = not chosen yet. Changed only
     * through {@see toggleSpreadsheetGrid()}. The mode is UX, not access control: whoever can
     * call the component can switch it on, so authorization is what guards the writes.
     */
    #[Locked]
    public ?bool $spreadsheetGridActive = null;

    /**
     * Override to configure the grid (autosave, saveUsing, authorizeUsing, atomic, limits).
     */
    protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
    {
        return $grid;
    }

    public function spreadsheetGridConfig(): SpreadsheetGrid
    {
        return $this->spreadsheetGridInstance ??= $this->spreadsheetGrid(
            SpreadsheetGrid::make()->componentAuthorization(fn (Model $record): ?bool => $this->canEditSpreadsheetGridRecord($record)),
        );
    }

    /**
     * The edit gate Filament itself uses on this component, or null when it has none (a
     * table widget, a custom page): the grid then asks the `update` policy through Filament.
     */
    protected function canEditSpreadsheetGridRecord(Model $record): ?bool
    {
        // Relation managers and "manage related records" pages: read-only on View pages,
        // then their own canEdit() (the related resource or the policy).
        if ($this instanceof RelationManager && $this->isReadOnly()) {
            return false;
        }

        if ($this instanceof RelationManager || $this instanceof ManageRelatedRecords) {
            return $this->canEdit($record);
        }

        // Resource pages (List records): the resource's canEdit(), overrides included.
        if ($this instanceof ResourcePage) {
            return $this::getResource()::canEdit($record);
        }

        return null;
    }

    /**
     * Whether cells are editable right now: always, unless the grid is toggleable.
     */
    public function isSpreadsheetGridActive(): bool
    {
        $grid = $this->spreadsheetGridConfig();

        if (!$grid->isToggleable()) {
            return true;
        }

        if ($this->spreadsheetGridActive !== null) {
            return $this->spreadsheetGridActive;
        }

        if ($grid->shouldPersistMode()) {
            return (bool) session()->get($this->spreadsheetGridSessionKey(), $grid->isActiveByDefault());
        }

        return $grid->isActiveByDefault();
    }

    public function toggleSpreadsheetGrid(bool $active): void
    {
        if (!$this->spreadsheetGridConfig()->isToggleable()) {
            return;
        }

        $this->spreadsheetGridActive = $active;

        if ($this->spreadsheetGridConfig()->shouldPersistMode()) {
            session()->put($this->spreadsheetGridSessionKey(), $active);
        }

        $this->flushCachedTableRecords();
    }

    protected function spreadsheetGridSessionKey(): string
    {
        return 'spreadsheet-grid.'.static::class;
    }

    /**
     * @return array<string, GridColumn>
     */
    public function spreadsheetGridColumns(): array
    {
        $this->ensureSpreadsheetGridSupportsTable();

        $columns = [];

        foreach ($this->getTable()->getColumns() as $name => $column) {
            // A hidden column (`->visible(fn ...)`, `->hidden(...)`) is neither sent to the
            // browser nor writable. A column the user only toggled off stays editable.
            if ($column instanceof GridColumn && !$column->isHidden()) {
                $columns[(string) $name] = $column;
            }
        }

        return $columns;
    }

    /**
     * A `BelongsToMany` table with `allowDuplicates()` keys its rows by the PIVOT key, while the
     * save looks records up by the model key: a pivot key equal to another model's id would
     * write to that model. Refused loudly on render and on save instead of guessing.
     */
    protected function ensureSpreadsheetGridSupportsTable(): void
    {
        if ($this->getTable()->hasPivotRecordKeys()) {
            throw new LogicException('The spreadsheet grid does not support tables keyed by pivot keys (a BelongsToMany table with allowDuplicates()).');
        }
    }

    /**
     * @return array<string, mixed> the config of the client component
     */
    public function spreadsheetGridClientConfig(): array
    {
        $grid = $this->spreadsheetGridConfig();
        $columns = [];

        foreach ($this->spreadsheetGridColumns() as $name => $column) {
            $columns[$name] = $column->toClientConfig();
        }

        return [
            'columns' => $columns,
            'order' => array_keys($columns),
            'autosave' => $grid->isAutosave(),
            'canToggleAutosave' => $grid->canToggleAutosave(),
            'toggleable' => $grid->isToggleable(),
            'active' => $this->isSpreadsheetGridActive(),
            'messages' => [
                'required' => __('spreadsheet-grid::messages.client_required'),
                'invalid' => __('spreadsheet-grid::messages.client_invalid'),
                'integer' => __('spreadsheet-grid::messages.client_integer'),
                'min' => __('spreadsheet-grid::messages.client_min'),
                'max' => __('spreadsheet-grid::messages.client_max'),
                'maxLength' => __('spreadsheet-grid::messages.client_max_length'),
                'failed' => __('spreadsheet-grid::messages.client_failed'),
                'confirmExit' => __('spreadsheet-grid::messages.confirm_exit'),
                'confirmLeave' => __('spreadsheet-grid::messages.confirm_leave'),
            ],
        ];
    }

    /**
     * The one endpoint of the grid: every pending edit in a single request.
     *
     * @param array<mixed> $changes   `[recordKey => [column => value]]`
     * @param array<mixed> $originals `[recordKey => [column => value as loaded]]`, for conflict detection
     * @param bool         $autosave  a save fired by autosave: only failures are notified
     *
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>, values: array<string, array<string, string>>}
     */
    public function saveSpreadsheetGrid(array $changes, array $originals = [], bool $autosave = false): array
    {
        $this->ensureSpreadsheetGridSupportsTable();

        $query = $this->getTable()->getQuery(isResolvingRecord: true);

        if (!($query instanceof Builder || $query instanceof Relation)) {
            throw new LogicException('The table has no Eloquent query to save through.');
        }

        $this->applySpreadsheetGridFilterScopes($query);

        $grid = $this->spreadsheetGridConfig();

        if (!$this->isSpreadsheetGridActive()) {
            $refused = new GridResult;

            foreach (array_keys($changes) as $key) {
                $refused->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.mode_off'));
            }

            return $refused->toArray();
        }

        $result = (new GridSaver($query, $this->spreadsheetGridColumns(), $grid, Filament::auth()->user()))->save($changes, $originals);

        $this->flushCachedTableRecords();

        // One toast per committed cell would flood the screen in autosave mode.
        if ($grid->shouldNotify() && !($autosave && $result->failedCount() === 0)) {
            $this->notifySpreadsheetGrid($autosave ? 0 : $result->savedCount(), $result->failedCount());
        }

        return $result->toArray();
    }

    /**
     * The filters' base queries only (TrashedFilter lifts the soft-delete scope there), as
     * Filament resolves a record for its own actions: a row the user made visible with
     * "With trashed" must stay saveable. The narrowing part of the filters is not applied.
     *
     * @param Builder<Model>|Relation<Model, Model, mixed> $query
     */
    protected function applySpreadsheetGridFilterScopes(Builder|Relation $query): void
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        $table = $this->getTable();

        $table->withAppliedFiltersFormState(function () use ($table, $builder): void {
            foreach ($table->getFilters() as $filter) {
                $filter->applyToBaseQuery($builder, $this->getTableFilterState($filter->getName()) ?? []);
            }
        });
    }

    protected function notifySpreadsheetGrid(int $saved, int $failed): void
    {
        if ($saved === 0 && $failed === 0) {
            return;
        }

        $notification = Notification::make();

        if ($failed === 0) {
            $notification->success()->title(trans_choice('spreadsheet-grid::messages.saved', $saved, ['count' => $saved]));
        } elseif ($saved === 0) {
            $notification->danger()->title(trans_choice('spreadsheet-grid::messages.failed', $failed, ['count' => $failed]));
        } else {
            $notification->warning()
                ->title(trans_choice('spreadsheet-grid::messages.saved', $saved, ['count' => $saved]))
                ->body(trans_choice('spreadsheet-grid::messages.failed', $failed, ['count' => $failed]));
        }

        $notification->send();
    }
}
