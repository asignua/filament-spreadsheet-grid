@php
    use Filament\Support\Facades\FilamentAsset;
@endphp

{{--
    Header of a table in spreadsheet mode: Save all / Discard, counters, the key hints.
    It also hosts the Alpine component that attaches itself to the surrounding table.
    `wire:ignore`: its state (dirty cells) must survive Livewire re-rendering the table.
--}}
<div
    wire:ignore
    class="sg-toolbar"
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('spreadsheet-grid', 'asignua/filament-spreadsheet-grid') }}"
    x-data="spreadsheetGrid(@js($config), $wire)"
>
    <div class="sg-toolbar-actions">
        @if ($config['toggleable'] ?? false)
            <x-filament::button
                size="sm"
                color="gray"
                x-on:click="toggle()"
                x-bind:disabled="saving || switching"
            >
                <span x-show="! active">{{ __('spreadsheet-grid::messages.edit_as_spreadsheet') }}</span>
                <span x-show="active && dirty === 0" x-cloak>{{ __('spreadsheet-grid::messages.done') }}</span>
                <span x-show="active && dirty > 0" x-cloak>{{ __('spreadsheet-grid::messages.discard_and_exit') }}</span>
            </x-filament::button>
        @endif

        <x-filament::button
            x-show="active"
            size="sm"
            color="primary"
            x-on:click="save()"
            x-bind:disabled="dirty === 0 || saving"
        >
            {{ __('spreadsheet-grid::messages.save_all') }}
            <span x-show="dirty > 0" x-text="'(' + dirty + ')'"></span>
        </x-filament::button>

        <x-filament::button
            x-show="active"
            size="sm"
            color="gray"
            x-on:click="discard()"
            x-bind:disabled="dirty === 0 || saving"
        >
            {{ __('spreadsheet-grid::messages.discard') }}
        </x-filament::button>

        <x-filament::loading-indicator x-show="saving" x-cloak class="sg-spinner" />

        @if ($config['canToggleAutosave'] ?? true)
            <label class="sg-autosave" x-show="active">
                <input type="checkbox" x-model="autosave" />
                {{ __('spreadsheet-grid::messages.autosave') }}
            </label>
        @endif

        <span class="sg-status" x-show="errors > 0" x-cloak x-text="errors + ' ⚠'"></span>
        <span class="sg-status" x-show="skipped > 0" x-cloak>
            {{ __('spreadsheet-grid::messages.skipped_readonly') }}
        </span>
    </div>

    <p class="sg-hint" x-show="active">{{ __('spreadsheet-grid::messages.hint') }}</p>
</div>
