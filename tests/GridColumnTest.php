<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Tests;

use Asignua\FilamentSpreadsheetGrid\Columns\GridColumn;
use Asignua\FilamentSpreadsheetGrid\Support\GridCellValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\DataProvider;

class GridColumnTest extends TestCase
{
    /**
     * @return array<string, array{GridColumn, mixed, mixed}>
     */
    public static function valid(): array
    {
        return [
            'text kept' => [GridColumn::make('a'), ' Hello ', ' Hello '],
            'text empty is null' => [GridColumn::make('a'), '', null],
            'number with spaces and comma' => [GridColumn::make('a')->number(), '1 250,5', '1250.5'],
            'number with thousands dot' => [GridColumn::make('a')->number(), '1.250,50', '1250.50'],
            'number empty' => [GridColumn::make('a')->number(), '  ', null],
            'integer' => [GridColumn::make('a')->integer(), '42', 42],
            'integer with zero fraction' => [GridColumn::make('a')->integer(), '42.0', 42],
            'boolean words' => [GridColumn::make('a')->boolean(), 'так', true],
            'boolean upper' => [GridColumn::make('a')->boolean(), 'FALSE', false],
            'boolean empty is off' => [GridColumn::make('a')->boolean(), '', false],
            'date' => [GridColumn::make('a')->date(), '2026-10-02', '2026-10-02'],
            'select' => [GridColumn::make('a')->select(['x' => 'X', 'y' => 'Y']), 'y', 'y'],
            'select int key' => [GridColumn::make('a')->select([1 => 'One']), '1', 1],
            'empty as value' => [GridColumn::make('a')->emptyAs(''), '', ''],
        ];
    }

    #[DataProvider('valid')]
    public function test_it_normalises_valid_input(GridColumn $column, mixed $raw, mixed $expected): void
    {
        [$value, $errors] = $column->prepare($raw);

        $this->assertSame([], $errors);
        $this->assertSame($expected, $value);
    }

    /**
     * @return array<string, array{GridColumn, mixed}>
     */
    public static function invalid(): array
    {
        return [
            'required empty' => [GridColumn::make('a')->required(), ''],
            'text too long' => [GridColumn::make('a')->maxLength(3), 'abcd'],
            'number not numeric' => [GridColumn::make('a')->number(), 'abc'],
            'number below min' => [GridColumn::make('a')->number(min: 0), '-1'],
            'number above max' => [GridColumn::make('a')->number(max: 10), '11'],
            'integer fraction' => [GridColumn::make('a')->integer(), '4.5'],
            'boolean junk' => [GridColumn::make('a')->boolean(), 'maybe'],
            'date wrong format' => [GridColumn::make('a')->date(), '02.10.2026'],
            'date impossible' => [GridColumn::make('a')->date(), '2026-02-31'],
            'select unknown' => [GridColumn::make('a')->select(['x' => 'X']), 'z'],
            'array value' => [GridColumn::make('a'), ['x']],
            'user rule' => [GridColumn::make('a')->rules([Rule::in(['ok'])]), 'nope'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_it_rejects_invalid_input_with_a_message(GridColumn $column, mixed $raw): void
    {
        [$value, $errors] = $column->prepare($raw);

        $this->assertNull($value);
        $this->assertNotSame([], $errors);
        $this->assertNotEmpty($errors[0]);
    }

    public function test_enum_options_use_the_case_values(): void
    {
        $column = GridColumn::make('a')->select(TestStatus::class);

        $this->assertSame(['draft' => 'Draft', 'live' => 'Live'], $column->getOptions());
        $this->assertSame(['live', []], $column->prepare('live'));
        $this->assertNotSame([], $column->prepare('gone')[1]);
    }

    public function test_cell_strings_for_the_browser(): void
    {
        $column = GridColumn::make('a');

        $this->assertSame('1', $column->toCellString(true));
        $this->assertSame('0', $column->toCellString(false));
        $this->assertSame('', $column->toCellString(null));
        $this->assertSame('live', $column->toCellString(TestStatus::Live));
        $this->assertSame('2026-10-02', $column->toCellString(now()->setDate(2026, 10, 2)));
        $this->assertSame('02.10.2026', $column->date('d.m.Y')->toDisplayString('2026-10-02'));
        $this->assertSame('2026-10-02', GridCellValue::formatDate('2026-10-02', 'Y-m-d'));
    }

    public function test_record_independent_options_are_evaluated_once_for_all_cells(): void
    {
        $calls = 0;
        $column = GridColumn::make('a')->select(function () use (&$calls): array {
            $calls++;

            return ['x' => 'X'];
        });

        // The saver and the table work on clones of the column, one per row.
        foreach (range(1, 5) as $ignored) {
            (clone $column)->getOptions();
            (clone $column)->prepare('x');
        }

        $this->assertSame(1, $calls);
    }

    public function test_record_dependent_options_are_evaluated_per_cell(): void
    {
        $calls = 0;
        $column = GridColumn::make('a')->select(function (?Model $record) use (&$calls): array {
            $calls++;

            return ['x' => 'X'];
        });

        $column->getOptions();
        $column->getOptions();

        $this->assertSame(2, $calls);
    }
}
