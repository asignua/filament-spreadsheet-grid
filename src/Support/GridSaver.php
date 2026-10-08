<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Support;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\SpreadsheetGrid;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Applies a batch of cell edits to the records of a table query.
 *
 * Input is `[recordKey => [column => rawValue]]` straight from the browser, so nothing in
 * it is trusted: the record must be reachable through the table's own query, the user must
 * be allowed to update it, the column must be an editable grid column of the table, and
 * every value goes through the column's type and rules.
 */
class GridSaver
{
    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $query   the table's base query (tenant scopes included, user filters not)
     * @param array<string, GridColumn>                    $columns the grid columns by name
     */
    public function __construct(
        protected Builder|Relation $query,
        protected array $columns,
        protected SpreadsheetGrid $grid,
        protected ?Authenticatable $user = null,
    ) {}

    /** @var array<string, array<string, string>> the values the client loaded, for conflict detection */
    protected array $originals = [];

    /**
     * @param array<mixed> $changes   `[recordKey => [column => rawValue]]`
     * @param array<mixed> $originals `[recordKey => [column => value the client loaded]]` (optional)
     */
    public function save(array $changes, array $originals = []): GridResult
    {
        $result = new GridResult;
        $changes = $this->sanitize($changes);
        $this->originals = $this->sanitizeOriginals($originals);

        if (($limit = $this->exceededLimit($changes)) !== null) {
            foreach (array_keys($changes) as $key) {
                $result->addError((string) $key, GridResult::ROW, $limit);
            }

            return $result;
        }

        if ($changes === []) {
            return $result;
        }

        $records = $this->records(array_keys($changes));

        /** @var array<string, array<string, mixed>> $ready */
        $ready = [];

        foreach ($changes as $key => $fields) {
            $key = (string) $key;
            $record = $records[$key] ?? null;

            if (!$record instanceof Model) {
                $result->addError($key, GridResult::ROW, __('spreadsheet-grid::messages.row_missing'));

                continue;
            }

            if (!$this->grid->canEdit($record, $this->user)) {
                $result->addError($key, GridResult::ROW, __('spreadsheet-grid::messages.row_forbidden'));

                continue;
            }

            $values = $this->prepare($key, $record, $fields, $result);

            if ($values !== null) {
                $ready[$key] = $values;
            }
        }

        // All or nothing: one bad row stops everything before the first write.
        if ($this->grid->isAtomic() && $result->hasErrors()) {
            foreach (array_keys($ready) as $key) {
                $result->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.atomic_aborted'));
            }

            return $result;
        }

        $this->write($ready, $records, $result);
        $this->readBack($ready, $result);

        return $result;
    }

    /**
     * @param array<mixed> $changes
     *
     * @return array<string, array<string, mixed>>
     */
    protected function sanitize(array $changes): array
    {
        $clean = [];

        foreach ($changes as $key => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $row = [];

            foreach ($fields as $field => $value) {
                if (is_string($field) && (is_scalar($value) || $value === null)) {
                    $row[$field] = $value;
                }
            }

            $clean[(string) $key] = $row;
        }

        return $clean;
    }

    /**
     * @param array<mixed> $originals
     *
     * @return array<string, array<string, string>>
     */
    protected function sanitizeOriginals(array $originals): array
    {
        $clean = [];

        foreach ($originals as $key => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                if (is_string($field) && (is_scalar($value) || $value === null)) {
                    $clean[(string) $key][$field] = (string) $value;
                }
            }
        }

        return $clean;
    }

    /**
     * @param array<string, array<string, mixed>> $changes
     *
     * @return string|null the message when the batch is over a limit
     */
    protected function exceededLimit(array $changes): ?string
    {
        if (count($changes) > $this->grid->getMaxRows()) {
            return __('spreadsheet-grid::messages.too_many', ['max' => $this->grid->getMaxRows()]);
        }

        if (array_sum(array_map(count(...), $changes)) > $this->grid->getMaxCells()) {
            return __('spreadsheet-grid::messages.too_many_cells', ['max' => $this->grid->getMaxCells()]);
        }

        return null;
    }

    /**
     * Only through the table's query: a record the table cannot show cannot be edited.
     *
     * @param list<int|string> $keys
     *
     * @return array<string, Model>
     */
    protected function records(array $keys): array
    {
        $records = [];

        foreach ($this->query->clone()->whereKey($keys)->get() as $record) {
            $records[(string) $record->getKey()] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null typed values, or null when any cell of the row failed
     */
    protected function prepare(string $key, Model $record, array $fields, GridResult $result): ?array
    {
        $values = [];
        $failed = false;

        foreach ($fields as $field => $raw) {
            $column = $this->columns[$field] ?? null;

            if (!$column instanceof GridColumn) {
                $result->addError($key, $field, __('spreadsheet-grid::messages.not_editable'));
                $failed = true;

                continue;
            }

            $column = (clone $column)->record($record);

            if (!$column->isEditableForRecord()) {
                $result->addError($key, $field, __('spreadsheet-grid::messages.not_editable'));
                $failed = true;

                continue;
            }

            if (($conflict = $this->conflict($key, $field, $column)) !== null) {
                $result->addError($key, $field, $conflict);
                $failed = true;

                continue;
            }

            [$value, $errors] = $column->prepare($raw, $record);

            if ($errors !== []) {
                $result->addError($key, $field, $errors);
                $failed = true;

                continue;
            }

            $values[$field] = $value;
        }

        return $failed || $values === [] ? null : $values;
    }

    /**
     * Last write must not win silently: when the client says what it loaded and the stored
     * value is different now, someone else saved in between.
     */
    protected function conflict(string $key, string $field, GridColumn $column): ?string
    {
        if (!$this->grid->shouldDetectConflicts() || !isset($this->originals[$key][$field])) {
            return null;
        }

        $current = $column->getCurrentCellString();

        if ($current === $this->originals[$key][$field]) {
            return null;
        }

        return __('spreadsheet-grid::messages.conflict', ['value' => $column->toDisplayString($current)]);
    }

    /**
     * The stored value of every sent cell of the saved rows, as the grid would render it:
     * it is the original the client compares against on the next save, so it must be what
     * the next render shows, not the text that was sent.
     *
     * @param array<string, array<string, mixed>> $ready
     */
    protected function readBack(array $ready, GridResult $result): void
    {
        $saved = array_values(array_filter($result->saved, fn (string $key): bool => isset($ready[$key])));

        if ($saved === []) {
            return;
        }

        // Through the query that loaded the rows (not Model::refresh()): attributes that come
        // from it, such as pivot columns, are read back too. A row it no longer finds was
        // deleted or moved out of reach by saveUsing(): nothing to read back.
        $fresh = $this->records($saved);

        foreach ($saved as $key) {
            $record = $fresh[$key] ?? null;

            if (!$record instanceof Model) {
                continue;
            }

            foreach (array_keys($ready[$key]) as $field) {
                $column = $this->columns[$field] ?? null;

                if ($column instanceof GridColumn) {
                    $result->values[$key][$field] = (clone $column)->record($record)->getCurrentCellString();
                }
            }
        }
    }

    /**
     * One transaction for the batch, a savepoint per row: in partial mode a failing row
     * rolls back alone, in atomic mode a failing row rolls back the batch.
     *
     * @param array<string, array<string, mixed>> $ready
     * @param array<string, Model>                $records
     */
    protected function write(array $ready, array $records, GridResult $result): void
    {
        if ($ready === []) {
            return;
        }

        $atomic = $this->grid->isAtomic();
        // The model's own connection: on the default one, a model with `$connection` would
        // autocommit every row and "all or nothing" would be a lie.
        $connection = $this->query->getModel()->getConnection();

        try {
            $connection->transaction(function () use ($ready, $records, $result, $atomic, $connection): void {
                foreach ($ready as $key => $values) {
                    try {
                        $connection->transaction(fn () => $this->persist($records[$key], $values));
                    } catch (ValidationException $exception) {
                        $this->reject($result, (string) $key, $exception);

                        if ($atomic) {
                            throw $exception;
                        }

                        continue;
                    } catch (QueryException $exception) {
                        report($exception);
                        $result->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.save_failed'));

                        if ($atomic) {
                            throw $exception;
                        }

                        continue;
                    }

                    $result->saved[] = (string) $key;
                }
            });
        } catch (ValidationException|QueryException) {
            // Atomic mode: the transaction is rolled back, so nothing counts as saved.
            $result->saved = [];

            foreach (array_keys($ready) as $key) {
                if (!$result->failed((string) $key)) {
                    $result->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.atomic_aborted'));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function persist(Model $record, array $values): void
    {
        $callback = $this->grid->getSaveUsing();

        if ($callback !== null) {
            $callback($record, $values);

            return;
        }

        foreach ($values as $field => $value) {
            if (str_contains($field, '.')) {
                throw new LogicException("The column [{$field}] is not an attribute of ".$record::class.'. Use SpreadsheetGrid::saveUsing() to write it.');
            }

            $record->setAttribute($field, $value);
        }

        // A `saving` listener that returns false cancels the write without an exception.
        if ($record->save() === false) {
            throw ValidationException::withMessages([GridResult::ROW => __('spreadsheet-grid::messages.save_failed')]);
        }
    }

    protected function reject(GridResult $result, string $key, ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            // A field the grid does not know (a DTO's own rule) lands on the row.
            $target = isset($this->columns[$field]) ? $field : GridResult::ROW;

            $result->addError($key, $target, $messages);
        }
    }
}
