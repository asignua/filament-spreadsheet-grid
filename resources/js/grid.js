import { ChangeSet, ROW_FIELD } from './changes.js'
import { coerceValue, displayValue, parseTsv, serializeTsv } from './clipboard.js'
import { fillDown, fillRight, inRect, pastePlan, rectOf } from './fill.js'
import { initialState, reduce } from './navigation.js'

const CELL = '[data-sg-cell]'

/**
 * Glue between the pure modules and the Filament table DOM. It attaches to the table
 * element (`.fi-ta`) with delegated listeners, so Livewire re-rendering rows never loses
 * a handler, and it repaints dirty/selected/error state after every DOM patch.
 *
 * Cells are `<div data-sg-cell data-sg-key data-sg-field data-sg-value [data-sg-readonly]>`
 * rendered by the GridColumn view; this class never needs anything else from the markup.
 */
export class GridController {
    /**
     * @param {object} options
     * @param {HTMLElement} options.root
     * @param {Object<string, object>} options.columns  field -> {type, label, options, required, min, max, maxLength, dateFormat}
     * @param {string[]} options.order                  fields in column order
     * @param {() => boolean} [options.autosave]
     * @param {(payload: object, originals: object, autosave: boolean) => Promise<object>} options.save
     * @param {Object<string, string>} [options.messages]
     * @param {(status: object) => void} [options.onChange]
     * @param {(text: string) => boolean} [options.confirm]
     */
    constructor(options) {
        this.root = options.root
        this.columns = options.columns
        this.order = options.order
        this.autosave = options.autosave ?? (() => false)
        this.saveRequest = options.save
        this.messages = options.messages ?? {}
        this.onChange = options.onChange ?? (() => {})
        this.confirm = options.confirm ?? ((text) => this.root.ownerDocument.defaultView?.confirm(text) ?? true)

        this.changes = new ChangeSet()
        this.state = initialState()
        this.editing = null
        this.dragging = false
        this.saving = false
        this.skipped = 0
        this.cache = null
        this.scheduled = false
        this.queue = Promise.resolve()
        this.abort = new (this.root.ownerDocument.defaultView?.AbortController ?? AbortController)()
    }

    attach() {
        const { signal } = this.abort
        const root = this.root

        root.addEventListener('keydown', (event) => this.onKeydown(event), { signal })
        root.addEventListener('mousedown', (event) => this.onMousedown(event), { signal })
        root.addEventListener('mouseover', (event) => this.onMouseover(event), { signal })
        root.addEventListener('dblclick', (event) => this.onDblclick(event), { signal })
        root.addEventListener('click', (event) => event.target.closest?.(CELL) && event.stopPropagation(), { signal, capture: true })
        root.addEventListener('focusin', (event) => this.onFocusin(event), { signal })
        root.addEventListener('focusout', (event) => this.onFocusout(event), { signal })
        root.addEventListener('copy', (event) => this.onCopy(event, false), { signal })
        root.addEventListener('cut', (event) => this.onCopy(event, true), { signal })
        root.addEventListener('paste', (event) => this.onPaste(event), { signal })

        const doc = root.ownerDocument
        doc.addEventListener('mouseup', () => (this.dragging = false), { signal })

        const win = doc.defaultView

        win?.addEventListener(
            'beforeunload',
            (event) => {
                if (this.changes.size > 0) {
                    event.preventDefault()
                    event.returnValue = ''
                }
            },
            { signal },
        )

        // SPA panels (`->spa()`) navigate with wire:navigate, which never fires beforeunload.
        doc.addEventListener(
            'livewire:navigate',
            (event) => {
                if (this.changes.size > 0 && !this.confirm(this.messages.confirmLeave ?? '')) {
                    event.preventDefault()
                }
            },
            { signal },
        )

        // Livewire patches the DOM under us (filters, sort, pagination, a save): paint again.
        this.observer = new (win?.MutationObserver ?? MutationObserver)(() => this.schedulePaint())
        this.observer.observe(root, { childList: true, subtree: true })

        this.paint()
    }

    destroy() {
        this.abort.abort()
        this.observer?.disconnect()
    }

    // ---- model ----------------------------------------------------------------------------

    model() {
        if (this.cache) {
            return this.cache
        }

        const rows = []
        const byKey = new Map()

        for (const el of this.root.querySelectorAll(CELL)) {
            const key = el.dataset.sgKey
            let row = byKey.get(key)

            if (!row) {
                row = { key, cells: new Map() }
                byKey.set(key, row)
                rows.push(row)
            }

            row.cells.set(el.dataset.sgField, el)
        }

        const fields = this.order.filter((field) => rows.some((row) => row.cells.has(field)))

        this.cache = { rows, fields, byKey }

        return this.cache
    }

    dims() {
        const { rows, fields } = this.model()

        return { rows: rows.length, cols: fields.length }
    }

    cellAt(row, col) {
        const { rows, fields } = this.model()
        const entry = rows[row]
        const field = fields[col]
        const el = entry?.cells.get(field)

        return el ? { el, key: entry.key, field, row, col } : null
    }

    cellFrom(el) {
        const cell = el.closest?.(CELL)

        if (!cell) {
            return null
        }

        const { rows, fields } = this.model()
        const row = rows.findIndex((entry) => entry.key === cell.dataset.sgKey)
        const col = fields.indexOf(cell.dataset.sgField)

        return row === -1 || col === -1 ? null : this.cellAt(row, col)
    }

    isReadonly(cell) {
        return cell.el.hasAttribute('data-sg-readonly')
    }

    originalOf(cell) {
        return cell.el.dataset.sgValue ?? ''
    }

    valueOf(cell) {
        return this.changes.get(cell.key, cell.field)?.value ?? this.originalOf(cell)
    }

    selection() {
        const { anchor, focus } = this.state

        return anchor && focus ? rectOf(anchor, focus) : null
    }

    // ---- editing --------------------------------------------------------------------------

    /**
     * Set a cell from text typed or pasted by the user. Returns false when the cell is
     * read-only (nothing is written).
     */
    setValue(cell, raw) {
        if (this.isReadonly(cell)) {
            return false
        }

        const column = this.columns[cell.field]
        const { ok, value } = coerceValue(column, raw)

        this.changes.set(cell.key, cell.field, value, this.originalOf(cell))

        const problem = ok ? this.validate(column, value) : this.messages.invalid

        if (problem) {
            this.changes.setError(cell.key, cell.field, [problem])
        } else {
            this.changes.clearError(cell.key, cell.field)
        }

        return true
    }

    /** The light client-side checks; the server has the final word. */
    validate(column, value) {
        if (value === '') {
            return column.required ? this.messages.required : null
        }

        // Same as the server: "5.0" is the integer 5.
        if (column.type === 'integer' && !/^-?\d+(\.0+)?$/.test(value)) {
            return this.messages.integer
        }

        if (column.type === 'number' || column.type === 'integer') {
            if (column.min !== null && column.min !== undefined && Number(value) < column.min) {
                return (this.messages.min ?? '').replace(':min', column.min)
            }

            if (column.max !== null && column.max !== undefined && Number(value) > column.max) {
                return (this.messages.max ?? '').replace(':max', column.max)
            }
        }

        // Code points, like the server's mb_strlen (an emoji is one character, not two).
        if (column.type === 'text' && column.maxLength && [...value].length > column.maxLength) {
            return (this.messages.maxLength ?? '').replace(':max', column.maxLength)
        }

        return null
    }

    startEdit(cell, { replace = false, char = '' } = {}) {
        if (!cell || this.isReadonly(cell)) {
            this.state = { ...this.state, mode: 'nav' }

            return
        }

        const column = this.columns[cell.field]

        if (column.type === 'boolean') {
            this.setValue(cell, this.valueOf(cell) === '1' ? '0' : '1')
            this.state = { ...this.state, mode: 'nav' }

            return
        }

        const doc = this.root.ownerDocument
        let editor

        if (column.type === 'select') {
            editor = doc.createElement('select')

            const blank = doc.createElement('option')
            blank.value = ''
            blank.textContent = '—'
            editor.append(blank)

            for (const option of column.options ?? []) {
                const node = doc.createElement('option')
                node.value = String(option.value)
                node.textContent = String(option.label)
                editor.append(node)
            }

            editor.value = this.valueOf(cell)
        } else {
            editor = doc.createElement('input')
            editor.type = column.type === 'date' ? 'date' : 'text'

            if (column.type === 'number' || column.type === 'integer') {
                editor.inputMode = 'decimal'
            }

            editor.value = replace && column.type !== 'date' ? char : this.valueOf(cell)
        }

        editor.className = 'sg-editor'
        editor.setAttribute('data-sg-editor', '')
        editor.setAttribute('aria-label', column.label ?? cell.field)

        cell.el.querySelector('.sg-display')?.setAttribute('hidden', '')
        cell.el.append(editor)
        this.editing = { cell, editor }
        editor.focus()

        if (!replace && editor.select && editor.tagName === 'INPUT') {
            editor.select()
        }
    }

    stopEdit(commit, refocus = true) {
        const editing = this.editing

        if (!editing) {
            return
        }

        this.editing = null

        const text = editing.editor.value

        editing.editor.remove()
        editing.cell.el.querySelector('.sg-display')?.removeAttribute('hidden')

        if (commit) {
            this.setValue(editing.cell, text)
        }

        // Keep the keyboard on the grid after the editor is gone (not when the user clicked away).
        if (refocus) {
            this.focusActive()
        }
    }

    focusActive() {
        const focus = this.state.focus
        const cell = focus ? this.cellAt(focus.row, focus.col) : null

        cell?.el.focus({ preventScroll: false })
    }

    // ---- events ---------------------------------------------------------------------------

    dispatch(action, event = null) {
        const dims = this.dims()
        const { state, effects, handled } = reduce(this.state, action, dims)

        this.state = state

        if (handled && event) {
            event.preventDefault()
        }

        let touched = false

        for (const effect of effects) {
            touched = this.perform(effect) || touched
        }

        this.paint()

        // The keyboard follows the active cell (and scrolls it into view). Not after a blur:
        // the user clicked somewhere else on purpose.
        if (action.type !== 'blur' && this.state.mode === 'nav' && !this.editing && this.state.focus) {
            this.focusActive()
        }

        if (touched && this.autosave()) {
            this.save(true)
        }

        return handled
    }

    /** @returns {boolean} whether the change set may have changed */
    perform(effect) {
        switch (effect.type) {
            case 'startEdit': {
                const focus = this.state.focus
                const cell = focus ? this.cellAt(focus.row, focus.col) : null

                this.startEdit(cell, effect)

                // A boolean toggles at once: that is a change.
                return cell !== null && this.columns[cell.field].type === 'boolean'
            }

            case 'commit':
                this.stopEdit(true, effect.blur !== true)

                return true

            case 'cancel':
                this.stopEdit(false)

                return false

            case 'clear':
                return this.assign(this.cellsOf(this.selection()).map((cell) => ({ cell, value: '' })))

            case 'fillDown':
            case 'fillRight': {
                const rect = this.selection()
                const read = (row, col) => {
                    const cell = this.cellAt(row, col)

                    return cell ? this.valueOf(cell) : ''
                }
                const plan = (effect.type === 'fillDown' ? fillDown : fillRight)(rect, read)

                return this.assign(plan.map(({ row, col, value }) => ({ cell: this.cellAt(row, col), value })))
            }

            default:
                return false
        }
    }

    cellsOf(rect) {
        const cells = []

        if (!rect) {
            return cells
        }

        for (let row = rect.top; row <= rect.bottom; row++) {
            for (let col = rect.left; col <= rect.right; col++) {
                const cell = this.cellAt(row, col)

                if (cell) {
                    cells.push(cell)
                }
            }
        }

        return cells
    }

    /**
     * Write many cells at once; read-only ones are counted, not written.
     *
     * @returns {boolean} whether anything was written
     */
    assign(assignments) {
        let written = false
        let skipped = 0

        for (const { cell, value } of assignments) {
            if (!cell) {
                continue
            }

            if (this.setValue(cell, value)) {
                written = true
            } else {
                skipped++
            }
        }

        this.skipped = skipped

        return written
    }

    keyAction(event) {
        return { type: 'key', key: event.key, shift: event.shiftKey, ctrl: event.ctrlKey || event.metaKey, alt: event.altKey }
    }

    onKeydown(event) {
        // Typing in the table's own search box, a filter or a bulk-action menu is not ours.
        if (event.isComposing || !event.target.closest?.(CELL)) {
            return
        }

        this.dispatch(this.keyAction(event), event)
    }

    onMousedown(event) {
        if (event.button !== 0 || event.target.closest?.('[data-sg-editor]')) {
            return
        }

        const cell = this.cellFrom(event.target)

        if (!cell) {
            return
        }

        event.preventDefault()
        this.dragging = true
        this.dispatch({ type: 'select', row: cell.row, col: cell.col, extend: event.shiftKey })
        this.focusActive()
    }

    onMouseover(event) {
        if (!this.dragging) {
            return
        }

        const cell = this.cellFrom(event.target)

        if (cell) {
            this.dispatch({ type: 'drag', row: cell.row, col: cell.col })
        }
    }

    onDblclick(event) {
        const cell = this.cellFrom(event.target)

        if (cell && !this.editing) {
            this.dispatch({ type: 'select', row: cell.row, col: cell.col })
            this.dispatch({ type: 'key', key: 'Enter' })
        }
    }

    /** Tabbing into the grid from outside selects the cell that received focus. */
    onFocusin(event) {
        if (this.state.focus || this.editing) {
            return
        }

        const cell = this.cellFrom(event.target)

        if (cell) {
            this.dispatch({ type: 'select', row: cell.row, col: cell.col })
        }
    }

    onFocusout(event) {
        if (this.editing && event.target === this.editing.editor) {
            this.dispatch({ type: 'blur' })
        }
    }

    onCopy(event, cut) {
        if (this.editing || !event.target.closest?.(CELL)) {
            return
        }

        const rect = this.selection()

        if (!rect || !event.clipboardData) {
            return
        }

        const rows = []

        for (let row = rect.top; row <= rect.bottom; row++) {
            const line = []

            for (let col = rect.left; col <= rect.right; col++) {
                const cell = this.cellAt(row, col)

                line.push(cell ? displayValue(this.columns[cell.field], this.valueOf(cell), { forClipboard: true }) : '')
            }

            rows.push(line)
        }

        event.clipboardData.setData('text/plain', serializeTsv(rows))
        event.preventDefault()

        if (cut) {
            this.dispatch({ type: 'key', key: 'Delete' })
        }
    }

    onPaste(event) {
        if (this.editing || !event.target.closest?.(CELL) || !event.clipboardData) {
            return
        }

        const matrix = parseTsv(event.clipboardData.getData('text/plain'))
        const rect = this.selection()

        if (matrix.length === 0 || !rect) {
            return
        }

        event.preventDefault()

        const plan = pastePlan(matrix, rect, this.dims())
        const written = this.assign(plan.map(({ row, col, value }) => ({ cell: this.cellAt(row, col), value })))

        // Select what was pasted, like a spreadsheet does.
        const last = plan[plan.length - 1]

        if (last) {
            this.state = {
                ...this.state,
                anchor: { row: rect.top, col: rect.left },
                focus: { row: last.row, col: last.col },
            }
        }

        this.paint()

        if (written && this.autosave()) {
            this.save(true)
        }
    }

    // ---- painting -------------------------------------------------------------------------

    schedulePaint() {
        if (this.scheduled) {
            return
        }

        this.scheduled = true
        this.cache = null

        const run = () => {
            this.scheduled = false
            this.paint()
        }

        ;(this.root.ownerDocument.defaultView?.requestAnimationFrame ?? setTimeout)(run)
    }

    paint() {
        this.cache = null

        if (this.editing && !this.editing.editor.isConnected) {
            this.editing = null
        }

        const selection = this.selection()
        const focus = this.state.focus
        const first = this.cellAt(0, 0)?.el

        for (const entry of this.model().rows) {
            for (const [field, el] of entry.cells) {
                const cell = this.cellFrom(el)
                const column = this.columns[field]
                const dirty = this.changes.isDirty(entry.key, field)
                const error = this.changes.errorFor(entry.key, field)
                const active = focus !== null && cell !== null && cell.row === focus.row && cell.col === focus.col

                el.classList.toggle('sg-dirty', dirty)
                el.classList.toggle('sg-error', error !== null)
                el.classList.toggle('sg-selected', cell !== null && inRect(selection, cell.row, cell.col))
                el.classList.toggle('sg-active', active)
                // One tab stop for the whole grid: the active cell, or the first one before any selection.
                el.setAttribute('tabindex', active || (focus === null && el === first) ? '0' : '-1')
                el.setAttribute('role', 'gridcell')
                el.setAttribute('aria-selected', String(cell !== null && inRect(selection, cell.row, cell.col)))

                if (error !== null) {
                    el.setAttribute('aria-invalid', 'true')
                    el.setAttribute('data-sg-error', error.join(' '))
                } else {
                    el.removeAttribute('aria-invalid')
                    el.removeAttribute('data-sg-error')
                }

                const display = el.querySelector('.sg-display')

                if (display && column) {
                    const text = displayValue(column, this.changes.get(entry.key, field)?.value ?? el.dataset.sgValue ?? '')

                    if (display.textContent !== text) {
                        display.textContent = text
                    }
                }
            }
        }

        // Our own mutations must not schedule another paint.
        this.observer?.takeRecords()
        this.notify()
    }

    notify() {
        this.onChange({
            dirty: this.changes.size,
            rows: this.changes.rowCount,
            errors: this.changes.errorCount,
            saving: this.saving,
            skipped: this.skipped,
        })
    }

    // ---- saving ---------------------------------------------------------------------------

    /**
     * Sends every pending change in ONE request; calls queue up so two saves never overlap.
     *
     * @param {boolean} auto  fired by autosave (the server then only notifies failures)
     */
    save(auto = false) {
        this.queue = this.queue.then(() => this.send(auto))

        return this.queue
    }

    async send(auto = false) {
        if (this.changes.size === 0) {
            return
        }

        const sent = this.changes.toPayload()
        const originals = this.changes.toOriginals()

        this.saving = true
        this.notify()

        try {
            const result = await this.saveRequest(sent, originals, auto)

            this.changes.applyResult(sent, result ?? {})
        } catch (error) {
            // A failed request leaves everything dirty and retryable; every sent row says so.
            for (const key of Object.keys(sent)) {
                this.changes.setError(key, ROW_FIELD, [this.messages.failed ?? String(error)])
            }
        } finally {
            this.saving = false
            this.paint()
        }
    }

    discard() {
        this.stopEdit(false)
        this.changes.clear()
        this.skipped = 0
        this.paint()
    }
}
