<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Concerns;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Asignua\FilamentSpreadsheetGrid\Support\GridResult;
use Asignua\FilamentSpreadsheetGrid\Support\GridSaver;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    /** The user's choice when the grid is `toggleable()`; null = not chosen yet. */
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
        return $this->spreadsheetGridInstance ??= $this->spreadsheetGrid(SpreadsheetGrid::make());
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
        $columns = [];

        foreach ($this->getTable()->getColumns() as $name => $column) {
            if ($column instanceof GridColumn) {
                $columns[(string) $name] = $column;
            }
        }

        return $columns;
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
            ],
        ];
    }

    /**
     * The one endpoint of the grid: every pending edit in a single request.
     *
     * @param array<mixed> $changes `[recordKey => [column => value]]`
     *
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>}
     */
    public function saveSpreadsheetGrid(array $changes): array
    {
        $query = $this->getTable()->getQuery();

        if (!($query instanceof Builder || $query instanceof Relation)) {
            throw new LogicException('The table has no Eloquent query to save through.');
        }

        $grid = $this->spreadsheetGridConfig();

        if (!$this->isSpreadsheetGridActive()) {
            $refused = new GridResult;

            foreach (array_keys($changes) as $key) {
                $refused->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.mode_off'));
            }

            return $refused->toArray();
        }

        $result = (new GridSaver($query, $this->spreadsheetGridColumns(), $grid, Filament::auth()->user()))->save($changes);

        $this->flushCachedTableRecords();

        if ($grid->shouldNotify()) {
            $this->notifySpreadsheetGrid($result->savedCount(), $result->failedCount());
        }

        return $result->toArray();
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
