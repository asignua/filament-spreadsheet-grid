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
     * @return array{saved: list<string>, errors: array<string, array<string, list<string>>>}
     */
    public function toArray(): array
    {
        return ['saved' => $this->saved, 'errors' => $this->errors];
    }
}
