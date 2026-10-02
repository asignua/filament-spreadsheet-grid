{{--
    One editable cell. All behaviour lives in the toolbar's client component, which reads
    these data attributes; the markup only has to say WHICH record, WHICH column, the stored
    value and whether the cell is read-only. With the page-level mode off, the column is
    plain text: no cell, no handlers, the table is an ordinary Filament table.
--}}
@if ($cell['active'])
    <div
        class="sg-cell"
        data-sg-cell
        data-sg-key="{{ $cell['key'] }}"
        data-sg-field="{{ $cell['field'] }}"
        data-sg-value="{{ $cell['value'] }}"
        @if ($cell['readonly']) data-sg-readonly @endif
        role="gridcell"
        tabindex="-1"
    >
        <span class="sg-display">{{ $cell['display'] }}</span>
    </div>
@else
    <span class="sg-plain">{{ $cell['display'] }}</span>
@endif
