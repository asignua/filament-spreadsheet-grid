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
use Illuminate\Support\Facades\DB;
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

    /**
     * @param array<mixed> $changes
     */
    public function save(array $changes): GridResult
    {
        $result = new GridResult;
        $changes = $this->sanitize($changes);

        if ($this->exceedsLimits($changes)) {
            foreach (array_keys($changes) as $key) {
                $result->addError((string) $key, GridResult::ROW, __('spreadsheet-grid::messages.too_many', ['max' => $this->grid->getMaxRows()]));
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
     * @param array<string, array<string, mixed>> $changes
     */
    protected function exceedsLimits(array $changes): bool
    {
        if (count($changes) > $this->grid->getMaxRows()) {
            return true;
        }

        return array_sum(array_map(count(...), $changes)) > $this->grid->getMaxCells();
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

        try {
            DB::transaction(function () use ($ready, $records, $result, $atomic): void {
                foreach ($ready as $key => $values) {
                    try {
                        DB::transaction(fn () => $this->persist($records[$key], $values));
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

        $record->save();
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
