<?php

declare(strict_types=1);

namespace Asignua\FilamentSpreadsheetGrid\Columns;

use Asignua\FilamentSpreadsheetGrid\Enums\GridCellType;
use Asignua\FilamentSpreadsheetGrid\Support\GridCellValue;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\Column;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Stringable;

/**
 * A table column whose cells are edited in place, spreadsheet style.
 *
 *     GridColumn::make('price')->number(min: 0)->required()
 *     GridColumn::make('status')->select(['draft' => 'Draft', 'live' => 'Live'])
 *     GridColumn::make('starts_on')->date('d.m.Y')
 *     GridColumn::make('is_active')->boolean()
 *
 * The column only describes the cell; the table component (trait
 * {@see \Asignua\FilamentSpreadsheetGrid\Concerns\InteractsWithSpreadsheetGrid}) saves.
 * The name is the attribute written by default; with `saveUsing()` on the grid it is just
 * the key of the change, so it may be anything (`price_eur`, `address.city`).
 */
class GridColumn extends Column
{
    protected string $view = 'spreadsheet-grid::column';

    protected GridCellType $cellType = GridCellType::Text;

    protected bool|Closure $isEditable = true;

    protected bool|Closure $isRequired = false;

    /** @var array<mixed>|class-string<BackedEnum>|Closure|null */
    protected array|Closure|string|null $options = null;

    /** @var array<mixed>|Closure */
    protected array|Closure $rules = [];

    protected int|float|Closure|null $min = null;

    protected int|float|Closure|null $max = null;

    protected int|Closure|null $maxLength = null;

    protected string $dateFormat = 'Y-m-d';

    protected mixed $emptyAs = null;

    public function text(): static
    {
        $this->cellType = GridCellType::Text;

        return $this;
    }

    public function number(int|float|Closure|null $min = null, int|float|Closure|null $max = null): static
    {
        $this->cellType = GridCellType::Number;
        $this->min = $min;
        $this->max = $max;
        $this->alignEnd();

        return $this;
    }

    public function integer(int|Closure|null $min = null, int|Closure|null $max = null): static
    {
        $this->cellType = GridCellType::Integer;
        $this->min = $min;
        $this->max = $max;
        $this->alignEnd();

        return $this;
    }

    /**
     * @param array<mixed>|class-string<BackedEnum>|Closure $options value => label, or a backed enum
     */
    public function select(array|Closure|string $options): static
    {
        $this->cellType = GridCellType::Select;
        $this->options = $options;

        return $this;
    }

    /**
     * @param string $displayFormat how the date is SHOWN (PHP tokens d, m, Y, y); stored and copied as Y-m-d
     */
    public function date(string $displayFormat = 'Y-m-d'): static
    {
        $this->cellType = GridCellType::Date;
        $this->dateFormat = $displayFormat;

        return $this;
    }

    public function boolean(): static
    {
        $this->cellType = GridCellType::Boolean;
        $this->alignCenter();

        return $this;
    }

    public function editable(bool|Closure $condition = true): static
    {
        $this->isEditable = $condition;

        return $this;
    }

    /**
     * An empty cell is an error. Without it an empty cell becomes `emptyAs()` (null).
     */
    public function required(bool|Closure $condition = true): static
    {
        $this->isRequired = $condition;

        return $this;
    }

    /**
     * Extra Laravel rules, as an array. A closure gets the record, so
     * `fn (Model $record) => [Rule::unique('products', 'sku')->ignore($record)]` works.
     *
     * @param array<mixed>|Closure $rules
     */
    public function rules(array|Closure $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    public function maxLength(int|Closure|null $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * What an emptied cell is stored as (default null; use '' or 0 for NOT NULL columns).
     */
    public function emptyAs(mixed $value): static
    {
        $this->emptyAs = $value;

        return $this;
    }

    protected function labelText(): string
    {
        return $this->plainText($this->getLabel());
    }

    protected function plainText(Htmlable|string|null $text): string
    {
        return $text instanceof Htmlable ? strip_tags($text->toHtml()) : (string) $text;
    }

    public function getCellType(): GridCellType
    {
        return $this->cellType;
    }

    public function isRequired(): bool
    {
        return (bool) $this->evaluate($this->isRequired);
    }

    public function getMin(): int|float|null
    {
        /** @var float|int|null */
        return $this->evaluate($this->min);
    }

    public function getMax(): int|float|null
    {
        /** @var float|int|null */
        return $this->evaluate($this->max);
    }

    public function getMaxLength(): ?int
    {
        /** @var int|null */
        return $this->evaluate($this->maxLength);
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function getEmptyValue(): mixed
    {
        return $this->emptyAs;
    }

    /**
     * Whether THIS record's cell is editable: the column flag, then the grid's authorization
     * (the record must be set on the column, which the table does while rendering).
     */
    public function isEditableForRecord(): bool
    {
        return (bool) $this->evaluate($this->isEditable);
    }

    /**
     * @return array<string, string> option value => label
     */
    public function getOptions(): array
    {
        $options = $this->evaluate($this->options);

        if (is_string($options) && enum_exists($options)) {
            $map = [];

            foreach ($options::cases() as $case) {
                if ($case instanceof BackedEnum) {
                    $map[(string) $case->value] = $case instanceof HasLabel ? $this->plainText($case->getLabel()) : $case->name;
                }
            }

            return $map;
        }

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        $map = [];

        foreach ((array) $options as $value => $label) {
            $map[(string) $value] = (string) $label;
        }

        return $map;
    }

    /**
     * @return list<mixed> extra rules for the record (user rules only)
     */
    public function getUserRules(?Model $record = null): array
    {
        $rules = $record instanceof Model
            ? $this->evaluate($this->rules, ['record' => $record])
            : $this->evaluate($this->rules);

        return array_values((array) $rules);
    }

    /**
     * Normalise, type-check and validate a raw value from the client.
     *
     * @return array{0: mixed, 1: list<string>} [typed value, error messages]
     */
    public function prepare(mixed $raw, ?Model $record = null): array
    {
        $label = $this->labelText();
        [$value, $problem] = GridCellValue::normalize($this->cellType, $raw, $this->getOptions());

        if ($problem !== null) {
            return [null, [__('spreadsheet-grid::messages.'.$problem, ['attribute' => $label])]];
        }

        $rules = [];

        if ($value === null) {
            $rules[] = $this->isRequired() ? 'required' : 'nullable';
        } else {
            $rules = $this->typeRules();
        }

        $rules = [...$rules, ...$this->getUserRules($record)];

        // The validator reads the field by name; a dotted column name would be nested.
        $validator = Validator::make(
            ['value' => $value],
            ['value' => $rules],
            [],
            ['value' => $label],
        );

        if ($validator->fails()) {
            return [null, array_values(array_map(strval(...), Arr::flatten($validator->errors()->get('value'))))];
        }

        return [$value ?? $this->emptyAs, []];
    }

    /**
     * @return list<string>
     */
    protected function typeRules(): array
    {
        $rules = [];

        switch ($this->cellType) {
            case GridCellType::Number:
                $rules[] = 'numeric';

                break;
            case GridCellType::Integer:
                $rules[] = 'integer';

                break;
            case GridCellType::Date:
                $rules[] = 'date_format:Y-m-d';

                break;
            default:
                break;
        }

        if ($this->cellType->isNumeric()) {
            if (($min = $this->getMin()) !== null) {
                $rules[] = 'min:'.$min;
            }

            if (($max = $this->getMax()) !== null) {
                $rules[] = 'max:'.$max;
            }
        }

        if ($this->cellType === GridCellType::Text && ($length = $this->getMaxLength()) !== null) {
            $rules[] = 'max:'.$length;
        }

        return $rules;
    }

    /**
     * The canonical string a cell carries to the browser (`data-sg-value`).
     */
    public function toCellString(mixed $state): string
    {
        return match (true) {
            $state === null => '',
            is_bool($state) => $state ? '1' : '0',
            $state instanceof BackedEnum => (string) $state->value,
            $state instanceof CarbonInterface => $state->format('Y-m-d'),
            is_scalar($state), $state instanceof Stringable => (string) $state,
            default => '',
        };
    }

    /**
     * What the cell shows; mirrors `displayValue()` in the client so a repaint does not flicker.
     */
    public function toDisplayString(string $value): string
    {
        return match ($this->cellType) {
            GridCellType::Boolean => $value === '1' ? '✓' : '✗',
            GridCellType::Select => $this->getOptions()[$value] ?? $value,
            GridCellType::Date => GridCellValue::formatDate($value, $this->dateFormat),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed> the cell as the view renders it
     */
    public function getCellData(): array
    {
        $record = $this->getRecord();
        $value = $this->toCellString($this->getState());

        return [
            'key' => (string) $this->getRecordKey(),
            'field' => $this->getName(),
            'value' => $value,
            'display' => $this->toDisplayString($value),
            'active' => $this->isGridActive(),
            'readonly' => !($record instanceof Model && $this->canEditRecord($record)),
        ];
    }

    protected function isGridActive(): bool
    {
        $livewire = $this->getTable()->getLivewire();

        return !method_exists($livewire, 'isSpreadsheetGridActive') || $livewire->isSpreadsheetGridActive();
    }

    protected function canEditRecord(Model $record): bool
    {
        if (!$this->isEditableForRecord()) {
            return false;
        }

        $livewire = $this->getTable()->getLivewire();

        if (method_exists($livewire, 'spreadsheetGridConfig')) {
            return $livewire->spreadsheetGridConfig()->canEdit($record);
        }

        return true;
    }

    /**
     * @return array<string, mixed> what the client component needs to know about the column
     */
    public function toClientConfig(): array
    {
        $options = [];

        foreach ($this->getOptions() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return [
            'type' => $this->cellType->value,
            'label' => $this->labelText(),
            'required' => $this->isRequired(),
            'min' => $this->cellType->isNumeric() ? $this->getMin() : null,
            'max' => $this->cellType->isNumeric() ? $this->getMax() : null,
            'maxLength' => $this->getMaxLength(),
            'dateFormat' => $this->dateFormat,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return [...parent::getViewData(), 'cell' => $this->getCellData()];
    }
}
