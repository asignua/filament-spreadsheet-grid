# Changelog

All notable changes to `asignua/filament-spreadsheet-grid` are documented here.

## Unreleased

- Dependencies: jsdom 30, esbuild 0.28 (dev); the built assets are unchanged.
- A cell the client could not read (an ambiguous number such as `1.250`, an unknown boolean word) stays dirty with its error and is never sent; before, `number()` saved `1.250` as 1.25 and an unknown boolean as `false`.
- A cell flagged invalid is never read back as the grid's own value: pasting the same ambiguous `1.250` twice, filling down/right from it or copying it and pasting it back keeps the error (before, the second entry became a valid 1.25, i.e. x1000 corruption). The server still cannot tell `1.250` typed by a user from `1.250` the grid holds, so it accepts it for `number()` columns; owner decision pending. Filling or pasting the grid's own copy over an invalid cell fixes it (a valid source such as `1.234` is read in the machine form), and copying an invalid cell puts its raw text on the clipboard (a boolean `maybe` is no longer turned into `FALSE`), so pasting it back stays invalid. Fill down/right is tracked per source cell (a valid `1.234` next to an invalid cell holding the same text still fills as valid), and so is copying: in a range mixing valid and invalid cells, the valid ones (a stored `1.234`) still paste in the machine form and only the invalid ones stay invalid.
- A Livewire re-render no longer deletes the open cell editor (kept out of the morph, restored when it is gone).
- Leaving a select or date editor that was not changed no longer clears a stored value the control cannot show; a stored value missing from the select options is listed.
- User rules are validated under the column's own name, so `unique:products` / `exists:skus` string rules work.
- Rows revealed by a filter's base query (`TrashedFilter` "With trashed") can be saved.
- A save cancelled by a model event (`saving` returning `false`) is reported as failed, not saved.
- `required()` rejects whitespace-only text, on the client and the server.
- The read-back after a save goes through the table's query, so pivot / joined columns keep their value.
- A save response no longer wipes client errors of cells that were not part of the request.
- The compiled CSS no longer ships a stray `.table` utility.

## v1.0.0 - 2026-10-05

- `GridColumn` (text, number, integer, select, date, boolean) turns table cells into spreadsheet cells.
- `InteractsWithSpreadsheetGrid` trait and `SpreadsheetGrid::toolbar()` header: one `saveSpreadsheetGrid()` endpoint, one request and one transaction per Save all, errors per row and cell.
- Keyboard navigation, range selection, TSV copy / cut / paste, fill down / right, per-cell client and server validation, dirty-cell highlighting, Discard, optional autosave.
- `SpreadsheetGrid::saveUsing()`, `authorizeUsing()`, `atomic()`, `autosave()`, `maxRows()`, `maxCells()`, `notify()`.
- Records are looked up through the table's base query and authorized on render and on save (see the authorization entry below).
- `SpreadsheetGrid::toggleable(default:, persist:)`: page-level "Edit as spreadsheet" mode; off = plain read-only cells and a refused save endpoint, leaving with unsaved edits asks first.
- Translations in 10 locales; Laravel Boost guidelines.
- Authorization follows Filament's own edit gates: the resource's `canEdit()` on resource pages, a relation manager's
  `canEdit()` and read-only state (relation managers on View pages are read-only), and the `update` policy through
  Filament elsewhere, so `strictAuthorization()` is honoured. `authorizeUsing()` still replaces all of it.
- Hidden grid columns (`->visible()` / `->hidden()`) are neither sent to the browser nor writable.
- Conflict detection: a cell whose stored value changed since the page was loaded is refused instead of overwritten
  (`detectConflicts(false)` to opt out). `saveSpreadsheetGrid()` takes the loaded values and an autosave flag as optional
  second and third arguments.
- The batch transaction runs on the model's own connection.
- `$spreadsheetGridActive` is `#[Locked]`; the mode switch is documented as UX, not access control.
- Select options are evaluated once per request unless the closure asks for the record; record-dependent options are
  sent per cell (`data-sg-options`, `optionsPerRecord` in the column config) and are never evaluated without a record.
- A cell edited again while its save is in flight takes the stored value as its original, so the next save is not
  refused as a conflict with the user's own change. `saveSpreadsheetGrid()` returns `values` — every sent cell of a saved
  row read back from the database as the grid renders it — so casts, mutators, `emptyAs()` and `saveUsing()`
  transformations (`10` stored as `10.00`) do not turn into a self-conflict.
- Conflict detection is documented as best effort (no row lock); only `ValidationException` / `QueryException` are
  isolated per row, any other exception rolls back the batch.
- Autosave notifies only failures; a failed request marks every sent row; `wire:navigate` asks before dropping edits.
- A `BelongsToMany` table with `allowDuplicates()` (rows keyed by the pivot key) throws a `LogicException` on render
  and on save instead of looking records up by the model key and possibly writing to another record.
- Numbers with one separator before exactly three digits (`1,000`, `1.250`) are refused as ambiguous on the client (never sent) instead of being read as decimals; the server refuses `1,000`-style values, while `1.250` in a `number()` column is stopped only on the client (a paste of thousands-separated values was saved 1000 times smaller);
  values the grid itself holds keep the dot as the decimal point.
- Client validation matches the server on `5.0` integers and on text length (code points); integers beyond PHP's range are
  rejected; the cell limit has its own message.
