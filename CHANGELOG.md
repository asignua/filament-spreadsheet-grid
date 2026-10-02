# Changelog

All notable changes to `asignua/filament-spreadsheet-grid` are documented here.

## v1.0.0 - unreleased

- `GridColumn` (text, number, integer, select, date, boolean) turns table cells into spreadsheet cells.
- `InteractsWithSpreadsheetGrid` trait and `SpreadsheetGrid::toolbar()` header: one `saveSpreadsheetGrid()` endpoint, one request and one transaction per Save all, errors per row and cell.
- Keyboard navigation, range selection, TSV copy / cut / paste, fill down / right, per-cell client and server validation, dirty-cell highlighting, Discard, optional autosave.
- `SpreadsheetGrid::saveUsing()`, `authorizeUsing()`, `atomic()`, `autosave()`, `maxRows()`, `maxCells()`, `notify()`.
- Records are looked up through the table's base query and checked against their `update` policy ability on render and on save.
- `SpreadsheetGrid::toggleable(default:, persist:)`: page-level "Edit as spreadsheet" mode; off = plain read-only cells and a refused save endpoint, leaving with unsaved edits asks first.
- Translations in 10 locales; Laravel Boost guidelines.
