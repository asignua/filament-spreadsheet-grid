import { GridController } from './grid.js'

/**
 * Alpine component of the toolbar (`x-data="spreadsheetGrid(config, $wire)"`). It attaches
 * a GridController to the surrounding `.fi-ta` table. The controller lives in a closure,
 * not on `this`: Alpine wraps component state in a Proxy, which breaks `#private` fields
 * and Maps.
 */
export default function spreadsheetGrid(config, $wire) {
    let controller = null

    return {
        dirty: 0,
        rows: 0,
        errors: 0,
        saving: false,
        skipped: 0,
        autosave: config.autosave === true,
        active: config.toggleable === true ? config.active === true : true,
        switching: false,

        init() {
            const root = this.$el.closest('.fi-ta') ?? this.$el.parentElement

            controller = new GridController({
                root,
                columns: config.columns,
                order: config.order,
                messages: config.messages,
                autosave: () => this.autosave,
                save: (payload, originals, auto) => $wire.saveSpreadsheetGrid(payload, originals, auto),
                confirm: config.confirm,
                onChange: (status) => Object.assign(this, status),
            })

            controller.attach()
        },

        destroy() {
            controller?.destroy()
            controller = null
        },

        /**
         * The page-level mode switch. Leaving with unsaved edits asks first and discards them;
         * the server flips the mode (cells re-render as plain text or as grid cells).
         */
        async toggle() {
            const turnOn = !this.active

            if (!turnOn && this.dirty > 0) {
                const ask = config.confirm ?? ((text) => globalThis.confirm(text))

                if (!ask(config.messages?.confirmExit ?? '')) {
                    return
                }

                controller?.discard()
            }

            this.switching = true

            try {
                await $wire.toggleSpreadsheetGrid(turnOn)
                this.active = turnOn
            } finally {
                this.switching = false
            }
        },

        save() {
            controller?.save()
        },

        discard() {
            controller?.discard()
        },
    }
}
