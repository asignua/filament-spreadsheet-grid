<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Support;

/**
 * Outcome of one batch save: which rows are written and which cells failed.
 */
final class GridResult
{
    /** Field used for a message that concerns the whole row. */
    public const string ROW = '*';

    /** @var list<string> */
    public array $saved = [];

    /** @var array<string, array<string, list<string>>> */
    public array $errors = [];

    /**
     * What each sent cell of a saved row holds now, as the grid renders it (`data-sg-value`):
     * casts, mutators, `emptyAs()` and `saveUsing()` can store something other than the text
     * that was sent ("10" becomes "10.00"), and the client takes this as the cell's new original.
     *
     * @var array<string, array<string, string>>
     */
    public array $values = [];

    /**
     * @param list<string>|string $messages
     */
    public function addError(string $key, string $field, array|string $messages): void
    {
        foreach ((array) $messages as $message) {
            $this->errors[$key][$field][] = $message;
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function failed(string $key): bool
    {
        return isset($this->errors[$key]);
    }

    public function savedCount(): int
    {
        return count($this->saved);
    }

    public function failedCount(): int
    {
        return count($this->errors);
    }

    /**
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>, values: array<string, array<string, string>>}
     */
    public function toArray(): array
    {
        return ['saved' => $this->saved, 'errors' => $this->errors, 'values' => $this->values];
    }
}
