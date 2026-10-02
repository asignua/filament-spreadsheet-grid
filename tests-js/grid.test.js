import assert from 'node:assert/strict'
import { test } from 'node:test'
import { JSDOM } from 'jsdom'
import { GridController } from '../resources/js/grid.js'

const columns = {
    name: { type: 'text', label: 'Name', required: true, maxLength: 5 },
    price: { type: 'number', label: 'Price', min: 0 },
    cat: { type: 'select', label: 'Category', options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }] },
    ok: { type: 'boolean', label: 'OK' },
}
const order = ['name', 'price', 'cat', 'ok']

const rowsData = [
    { key: '1', name: 'One', price: '1.00', cat: 'a', ok: '1' },
    { key: '2', name: 'Two', price: '2.00', cat: 'b', ok: '0', readonly: ['price'] },
    { key: '3', name: 'Three', price: '3.00', cat: '', ok: '0' },
]

function html() {
    const body = rowsData
        .map(
            (row) =>
                `<tr>${order
                    .map(
                        (field) =>
                            `<td><div data-sg-cell data-sg-key="${row.key}" data-sg-field="${field}" data-sg-value="${row[field]}" ${(row.readonly ?? []).includes(field) ? 'data-sg-readonly' : ''} tabindex="-1"><span class="sg-display">${row[field]}</span></div></td>`,
                    )
                    .join('')}</tr>`,
        )
        .join('')

    return `<!doctype html><div class="fi-ta"><input id="search" /><table><tbody>${body}</tbody></table></div>`
}

function setup({ save, autosave = false, confirm } = {}) {
    const dom = new JSDOM(html(), { pretendToBeVisual: true })
    const { window } = dom
    const root = window.document.querySelector('.fi-ta')
    const status = {}
    const sent = []
    const meta = []
    const controller = new GridController({
        root,
        columns,
        order,
        autosave: () => autosave,
        save: async (payload, originals, auto) => {
            sent.push(payload)
            meta.push({ originals, auto })

            return save ? save(payload) : { saved: Object.keys(payload), errors: {} }
        },
        messages: { required: 'Required', invalid: 'Invalid', integer: 'Integer', min: 'Min :min', max: 'Max :max', maxLength: 'Max :max chars', failed: 'Failed' },
        onChange: (s) => Object.assign(status, s),
        confirm,
    })

    controller.attach()

    const cell = (key, field) => root.querySelector(`[data-sg-key="${key}"][data-sg-field="${field}"]`)
    const key = (target, k, extra = {}) => {
        const event = new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...extra })

        target.dispatchEvent(event)

        return event
    }
    const click = (el, extra = {}) => el.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0, ...extra }))
    const active = () => window.document.activeElement
    const type = (text) => {
        const editor = root.querySelector('[data-sg-editor]')

        editor.value = text
    }
    const paste = (text) => {
        const event = new window.Event('paste', { bubbles: true, cancelable: true })

        event.clipboardData = { getData: () => text }
        active().dispatchEvent(event)

        return event
    }
    const copy = () => {
        let data = ''
        const event = new window.Event('copy', { bubbles: true, cancelable: true })

        event.clipboardData = { setData: (_, value) => (data = value) }
        active().dispatchEvent(event)

        return data
    }

    return { window, root, controller, status, sent, meta, cell, key, click, active, type, paste, copy }
}

test('clicking a cell activates it and focuses it; arrows move', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    assert.equal(g.active(), g.cell('1', 'name'))
    assert.ok(g.cell('1', 'name').classList.contains('sg-active'))

    const event = g.key(g.active(), 'ArrowRight')

    assert.equal(event.defaultPrevented, true)
    assert.equal(g.active(), g.cell('1', 'price'))
    g.key(g.active(), 'ArrowDown')
    assert.equal(g.active(), g.cell('2', 'price'))
})

test('typing starts an edit, Enter commits, moves down and marks the cell dirty', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.key(g.active(), 'X')

    const editor = g.root.querySelector('[data-sg-editor]')

    assert.ok(editor)
    assert.equal(editor.value, 'X')

    g.type('Xray')
    g.key(editor, 'Enter')

    assert.equal(g.root.querySelector('[data-sg-editor]'), null)
    assert.ok(g.cell('1', 'name').classList.contains('sg-dirty'))
    assert.equal(g.cell('1', 'name').querySelector('.sg-display').textContent, 'Xray')
    assert.equal(g.active(), g.cell('2', 'name'))
    assert.equal(g.status.dirty, 1)
})

test('Escape cancels an edit and leaves the cell untouched', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.key(g.active(), 'Enter')
    g.type('Changed')
    g.key(g.root.querySelector('[data-sg-editor]'), 'Escape')

    assert.equal(g.status.dirty, 0)
    assert.equal(g.cell('1', 'name').querySelector('.sg-display').textContent, 'One')
    assert.equal(g.root.querySelector('[data-sg-editor]'), null)
})

test('editing back to the original value is not a change', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.key(g.active(), 'Enter')
    g.type('One')
    g.key(g.root.querySelector('[data-sg-editor]'), 'Tab')

    assert.equal(g.status.dirty, 0)
    assert.equal(g.active(), g.cell('1', 'price'))
})

test('keys typed outside the cells (the search box) are not hijacked', () => {
    const g = setup()
    const search = g.root.querySelector('#search')
    const event = g.key(search, 'ArrowDown')

    assert.equal(event.defaultPrevented, false)
})

test('client validation marks required, bad numbers and length at once', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.key(g.active(), 'Enter')
    g.type('')
    g.key(g.root.querySelector('[data-sg-editor]'), 'Tab')
    assert.equal(g.cell('1', 'name').getAttribute('data-sg-error'), 'Required')

    g.key(g.active(), 'x')
    g.type('abc')
    g.key(g.root.querySelector('[data-sg-editor]'), 'Tab')
    assert.equal(g.cell('1', 'price').getAttribute('data-sg-error'), 'Invalid')
    assert.equal(g.status.errors, 2)
})

test('a boolean cell toggles on Enter without an editor', () => {
    const g = setup()

    g.click(g.cell('1', 'ok'))
    g.key(g.active(), 'Enter')

    assert.equal(g.root.querySelector('[data-sg-editor]'), null)
    assert.equal(g.cell('1', 'ok').querySelector('.sg-display').textContent, '✗')
    assert.equal(g.status.dirty, 1)
})

test('pasting a TSV block lands at the active cell, coerces types and skips read-only cells', () => {
    const g = setup()

    g.click(g.cell('1', 'price'))
    g.paste('9,5\tBeta\n7\tAlpha\n')

    assert.equal(g.cell('1', 'price').querySelector('.sg-display').textContent, '9.5')
    assert.equal(g.cell('1', 'cat').querySelector('.sg-display').textContent, 'Beta')
    // Row 2's price is read-only: skipped; its category still changes.
    assert.equal(g.cell('2', 'price').querySelector('.sg-display').textContent, '2.00')
    assert.equal(g.cell('2', 'cat').querySelector('.sg-display').textContent, 'Alpha')
    assert.equal(g.status.skipped, 1)
    assert.equal(g.status.dirty, 3)
})

test('pasting one value over a selected range fills the range', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.click(g.cell('3', 'name'), { shiftKey: true })
    g.paste('Same')

    for (const key of ['1', '2', '3']) {
        assert.equal(g.cell(key, 'name').querySelector('.sg-display').textContent, 'Same')
    }
})

test('copy puts the selection on the clipboard as TSV with raw values', () => {
    const g = setup()

    g.click(g.cell('1', 'cat'))
    g.click(g.cell('2', 'ok'), { shiftKey: true })

    assert.equal(g.copy(), 'Alpha\tTRUE\nBeta\tFALSE')
})

test('Ctrl+D fills the selection down from its first row', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.click(g.cell('3', 'name'), { shiftKey: true })
    g.key(g.active(), 'd', { ctrlKey: true })

    assert.equal(g.cell('2', 'name').querySelector('.sg-display').textContent, 'One')
    assert.equal(g.cell('3', 'name').querySelector('.sg-display').textContent, 'One')
})

test('Delete clears the selected cells', () => {
    const g = setup()

    g.click(g.cell('3', 'name'))
    g.key(g.active(), 'Delete')

    // name is required: cleared but flagged.
    assert.equal(g.cell('3', 'name').getAttribute('data-sg-error'), 'Required')
})

test('Save all sends every pending cell in ONE request and forgets the saved rows', async () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.paste('Uno\t5\nDos\t6')
    await g.controller.save()

    assert.equal(g.sent.length, 1)
    assert.deepEqual(g.sent[0], { 1: { name: 'Uno', price: '5' }, 2: { name: 'Dos' } })
    assert.equal(g.status.dirty, 0)
    assert.equal(g.cell('1', 'name').classList.contains('sg-dirty'), false)
})

test('server errors stay on their cells and the failed row stays dirty', async () => {
    const g = setup({ save: () => ({ saved: ['1'], errors: { 2: { name: ['Taken'] } } }) })

    g.click(g.cell('1', 'name'))
    g.paste('Uno\nDos')
    await g.controller.save()

    assert.equal(g.cell('2', 'name').getAttribute('data-sg-error'), 'Taken')
    assert.ok(g.cell('2', 'name').classList.contains('sg-error'))
    assert.ok(g.cell('2', 'name').classList.contains('sg-dirty'))
    assert.equal(g.cell('1', 'name').classList.contains('sg-dirty'), false)
    assert.equal(g.status.dirty, 1)
    assert.equal(g.status.errors, 1)
})

test('a row-level error marks every cell of the row', async () => {
    const g = setup({ save: () => ({ saved: [], errors: { 1: { '*': ['Not allowed'] } } }) })

    g.click(g.cell('1', 'name'))
    g.paste('Uno')
    await g.controller.save()

    assert.equal(g.cell('1', 'price').getAttribute('data-sg-error'), 'Not allowed')
})

test('Discard drops all pending edits and errors', () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.paste('Uno\nDos')
    g.controller.discard()

    assert.equal(g.status.dirty, 0)
    assert.equal(g.cell('2', 'name').querySelector('.sg-display').textContent, 'Two')
})

test('autosave sends right after a commit', async () => {
    const g = setup({ autosave: true })

    g.click(g.cell('1', 'name'))
    g.key(g.active(), 'Enter')
    g.type('Auto')
    g.key(g.root.querySelector('[data-sg-editor]'), 'Enter')
    await g.controller.queue

    assert.equal(g.sent.length, 1)
    assert.deepEqual(g.sent[0], { 1: { name: 'Auto' } })
})

test('two saves never overlap', async () => {
    let running = 0
    let peak = 0
    const g = setup({
        save: async (payload) => {
            running++
            peak = Math.max(peak, running)
            await new Promise((resolve) => setTimeout(resolve, 5))
            running--

            return { saved: Object.keys(payload) }
        },
    })

    g.click(g.cell('1', 'name'))
    g.paste('A')
    g.controller.save()
    g.paste('B')
    await g.controller.save()

    assert.equal(peak, 1)
})

test('after Livewire re-renders the cells, dirty state is painted again', async () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.paste('Uno')

    // Simulate a morph: the server puts the original text back and drops our classes.
    const el = g.cell('1', 'name')

    el.classList.remove('sg-dirty')
    el.innerHTML = '<span class="sg-display">One</span>'

    await new Promise((resolve) => g.window.requestAnimationFrame(() => setTimeout(resolve, 0)))

    assert.ok(g.cell('1', 'name').classList.contains('sg-dirty'))
    assert.equal(g.cell('1', 'name').querySelector('.sg-display').textContent, 'Uno')
})

test('the grid is one tab stop and tabbing into it selects that cell', () => {
    const g = setup()

    assert.equal(g.cell('1', 'name').getAttribute('tabindex'), '0')
    assert.equal(g.cell('2', 'name').getAttribute('tabindex'), '-1')

    g.cell('1', 'name').focus()

    assert.ok(g.cell('1', 'name').classList.contains('sg-active'))
})

test('each save carries what the client loaded, and says whether autosave fired it', async () => {
    const g = setup()

    g.click(g.cell('1', 'name'))
    g.paste('Uno\t5')
    await g.controller.save()

    assert.deepEqual(g.meta[0], { originals: { 1: { name: 'One', price: '1.00' } }, auto: false })

    const a = setup({ autosave: true })

    a.click(a.cell('1', 'name'))
    a.key(a.active(), 'Enter')
    a.type('Auto')
    a.key(a.root.querySelector('[data-sg-editor]'), 'Enter')
    await a.controller.queue

    assert.equal(a.meta[0].auto, true)
})

test('a failed request marks every sent row, not only the first', async () => {
    const g = setup({ save: () => Promise.reject(new Error('offline')) })

    g.click(g.cell('1', 'name'))
    g.paste('Uno\nDos')
    await g.controller.save()

    assert.equal(g.cell('1', 'name').getAttribute('data-sg-error'), 'Failed')
    assert.equal(g.cell('2', 'name').getAttribute('data-sg-error'), 'Failed')
    assert.equal(g.status.dirty, 2)
})

test('SPA navigation (wire:navigate) asks before dropping pending edits', () => {
    let answer = false
    const asked = []
    const g = setup({ confirm: (text) => (asked.push(text), answer) })
    const navigate = () => {
        const event = new g.window.Event('livewire:navigate', { cancelable: true })

        g.window.document.dispatchEvent(event)

        return event
    }

    assert.equal(navigate().defaultPrevented, false)
    assert.equal(asked.length, 0)

    g.click(g.cell('1', 'name'))
    g.paste('Uno')

    assert.equal(navigate().defaultPrevented, true)
    answer = true
    assert.equal(navigate().defaultPrevented, false)
    assert.equal(asked.length, 2)
})

test('client validation agrees with the server on integers and lengths', () => {
    const g = setup()

    assert.equal(g.controller.validate({ type: 'integer' }, '5.0'), null)
    assert.equal(g.controller.validate({ type: 'integer' }, '5.5'), 'Integer')
    // An emoji is one character, as for the server's mb_strlen.
    assert.equal(g.controller.validate({ type: 'text', maxLength: 2 }, '😀😀'), null)
    assert.equal(g.controller.validate({ type: 'text', maxLength: 2 }, '😀😀😀'), 'Max 2 chars')
})
