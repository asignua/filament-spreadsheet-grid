# Changelog

All notable changes to `asignua/filament-spreadsheet-grid` are documented here.

## v1.0.0 - unreleased

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
- A cell edited again while its save is in flight takes the saved value as its original, so the next save is not
  refused as a conflict with the user's own change.
- Conflict detection is documented as best effort (no row lock); only `ValidationException` / `QueryException` are
  isolated per row, any other exception rolls back the batch.
- Autosave notifies only failures; a failed request marks every sent row; `wire:navigate` asks before dropping edits.
- Client validation matches the server on `5.0` integers and on text length (code points); integers beyond PHP's range are
  rejected; the cell limit has its own message.
