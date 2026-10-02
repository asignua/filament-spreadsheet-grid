<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid;

use Closure;
use Filament\Facades\Filament;

use function Filament\get_authorization_response;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use WeakMap;

/**
 * Behaviour of one spreadsheet grid, set up in the table component's
 * `spreadsheetGrid()` hook:
 *
 *     protected function spreadsheetGrid(SpreadsheetGrid $grid): SpreadsheetGrid
 *     {
 *         return $grid
 *             ->saveUsing(fn (Product $record, array $changes) => app(ProductRepository::class)->update($record, $changes))
 *             ->authorizeUsing(fn (Product $record): bool => auth()->user()->can('update', $record))
 *             ->autosave();
 *     }
 */
/**
 * @phpstan-consistent-constructor
 */
class SpreadsheetGrid
{
    public const int DEFAULT_MAX_ROWS = 500;

    public const int DEFAULT_MAX_CELLS = 5000;

    protected bool $autosave = false;

    protected bool $canToggleAutosave = true;

    protected bool $atomic = false;

    protected bool $notify = true;

    protected bool $toggleable = false;

    protected bool $activeByDefault = false;

    protected bool $persistMode = false;

    protected ?Closure $saveUsing = null;

    protected ?Closure $authorizeUsing = null;

    /**
     * Set by the table component: its own Filament edit gate (`Resource::canEdit()`, a
     * relation manager's `canEdit()` / `isReadOnly()`). Returns null when it has none.
     */
    protected ?Closure $componentAuthorization = null;

    protected bool $detectConflicts = true;

    protected int $maxRows;

    protected int $maxCells;

    /** @var WeakMap<Model, bool> */
    protected WeakMap $authorized;

    public function __construct()
    {
        $this->maxRows = (int) config('spreadsheet-grid.max_rows', self::DEFAULT_MAX_ROWS);
        $this->maxCells = (int) config('spreadsheet-grid.max_cells', self::DEFAULT_MAX_CELLS);
        $this->authorized = new WeakMap;
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * The table header with the toolbar (Save all / Discard / counters) and the client
     * component. Without it the cells render but nothing is wired up.
     *
     *     $table->header(SpreadsheetGrid::toolbar())
     */
    public static function toolbar(): Closure
    {
        return static function (object $livewire): View {
            /** @var view-string $view */
            $view = 'spreadsheet-grid::toolbar';

            return view($view, [
                'config' => method_exists($livewire, 'spreadsheetGridClientConfig') ? $livewire->spreadsheetGridClientConfig() : [],
            ]);
        };
    }

    /**
     * Save every edit right after it is committed (one request per commit) instead of
     * waiting for "Save all". The user can switch it off unless `autosave(toggle: false)`.
     */
    public function autosave(bool $condition = true, bool $toggle = true): static
    {
        $this->autosave = $condition;
        $this->canToggleAutosave = $toggle;

        return $this;
    }

    /**
     * All or nothing: when any row fails validation or authorization, nothing is written.
     * Default is partial: valid rows are saved, failed ones are reported and stay dirty.
     */
    public function atomic(bool $condition = true): static
    {
        $this->atomic = $condition;

        return $this;
    }

    /**
     * Page-level mode switch. Off until the user turns it on ("Edit as spreadsheet"): while it
     * is off, grid columns render as plain read-only text and the table behaves like any
     * Filament table (row clicks, `recordUrl()`). Without `toggleable()` the grid is always
     * editable.
     *
     * @param bool $default whether the table starts in spreadsheet mode
     * @param bool $persist remember the choice per session and component
     */
    public function toggleable(bool $condition = true, bool $default = false, bool $persist = false): static
    {
        $this->toggleable = $condition;
        $this->activeByDefault = $default;
        $this->persistMode = $persist;

        return $this;
    }

    public function isToggleable(): bool
    {
        return $this->toggleable;
    }

    public function isActiveByDefault(): bool
    {
        return $this->activeByDefault;
    }

    public function shouldPersistMode(): bool
    {
        return $this->persistMode;
    }

    /**
     * Show a Filament notification with the outcome of a save.
     */
    public function notify(bool $condition = true): static
    {
        $this->notify = $condition;

        return $this;
    }

    /**
     * Your own write path (repositories, DTOs, services, models without mass assignment).
     * Receives the record and the VALIDATED, typed changes `[column => value]` of one row.
     * Throw a `ValidationException` to reject the row with messages; the row's writes made
     * by the callback roll back with the rest of its transaction.
     *
     * @param Closure(Model, array<string, mixed>): mixed $callback
     */
    public function saveUsing(?Closure $callback): static
    {
        $this->saveUsing = $callback;

        return $this;
    }

    /**
     * Who may edit a record, replacing the default. The default asks the same gate Filament
     * asks for its Edit action: the resource's `canEdit()` on a resource page, the relation
     * manager's `canEdit()` and `isReadOnly()` on a relation manager, and otherwise the
     * record's `update` policy ability through Filament (a model without a policy is
     * editable unless the panel uses `strictAuthorization()`).
     *
     * @param Closure(Model): bool $callback
     */
    public function authorizeUsing(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * @internal called by {@see Concerns\InteractsWithSpreadsheetGrid} with the component's own gate
     *
     * @param Closure(Model): ?bool $callback
     */
    public function componentAuthorization(?Closure $callback): static
    {
        $this->componentAuthorization = $callback;

        return $this;
    }

    /**
     * Refuse a cell whose stored value changed since the page was loaded (another editor
     * saved in between), instead of overwriting it silently. On by default.
     */
    public function detectConflicts(bool $condition = true): static
    {
        $this->detectConflicts = $condition;

        return $this;
    }

    public function shouldDetectConflicts(): bool
    {
        return $this->detectConflicts;
    }

    public function maxRows(int $rows): static
    {
        $this->maxRows = $rows;

        return $this;
    }

    public function maxCells(int $cells): static
    {
        $this->maxCells = $cells;

        return $this;
    }

    public function isAutosave(): bool
    {
        return $this->autosave;
    }

    public function canToggleAutosave(): bool
    {
        return $this->canToggleAutosave;
    }

    public function isAtomic(): bool
    {
        return $this->atomic;
    }

    public function shouldNotify(): bool
    {
        return $this->notify;
    }

    public function getSaveUsing(): ?Closure
    {
        return $this->saveUsing;
    }

    public function getMaxRows(): int
    {
        return $this->maxRows;
    }

    public function getMaxCells(): int
    {
        return $this->maxCells;
    }

    public function canEdit(Model $record, ?Authenticatable $user = null): bool
    {
        return $this->authorized[$record] ??= $this->decide($record, $user);
    }

    protected function decide(Model $record, ?Authenticatable $user): bool
    {
        if ($this->authorizeUsing instanceof Closure) {
            return (bool) ($this->authorizeUsing)($record);
        }

        $current = Filament::auth()->user();

        // Filament's own gates always speak for the logged-in user.
        if ($user === null || $user === $current) {
            if ($this->componentAuthorization instanceof Closure) {
                $answer = ($this->componentAuthorization)($record);

                if ($answer !== null) {
                    return (bool) $answer;
                }
            }

            // Policy, `Gate::before()` and strict authorization, exactly as Filament decides.
            return get_authorization_response('update', $record)->allowed();
        }

        $gate = Gate::forUser($user);
        $policy = $gate->getPolicyFor($record);

        if ($policy !== null && method_exists($policy, 'update')) {
            return $gate->allows('update', $record);
        }

        return !Filament::isAuthorizationStrict();
    }
}
