<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Enums;

enum GridCellType: string
{
    case Text = 'text';
    case Number = 'number';
    case Integer = 'integer';
    case Select = 'select';
    case Date = 'date';
    case Boolean = 'boolean';

    public function isNumeric(): bool
    {
        return $this === self::Number || $this === self::Integer;
    }
}
