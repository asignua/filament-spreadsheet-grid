import assert from 'node:assert/strict'
import { test } from 'node:test'
import { JSDOM } from 'jsdom'
import spreadsheetGrid from '../resources/js/index.js'

function setup({ toggleable = true, active = false, confirm = () => true } = {}) {
    const dom = new JSDOM(
        '<div class="fi-ta"><div id="bar"></div><table><tr><td><div data-sg-cell data-sg-key="1" data-sg-field="name" data-sg-value="One"><span class="sg-display">One</span></div></td></tr></table></div>',
        { pretendToBeVisual: true },
    )
    const calls = []
    const wire = { toggleSpreadsheetGrid: async (on) => calls.push(on), saveSpreadsheetGrid: async () => ({ saved: [] }) }
    const component = spreadsheetGrid(
        {
            toggleable,
            active,
            confirm,
            columns: { name: { type: 'text', label: 'Name' } },
            order: ['name'],
            messages: { confirmExit: 'Leave?' },
        },
        wire,
    )

    component.$el = dom.window.document.getElementById('bar')
    component.init()

    const cell = dom.window.document.querySelector('[data-sg-cell]')
    const edit = (text) => {
        cell.dispatchEvent(new dom.window.MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 }))
        cell.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
        cell.querySelector('[data-sg-editor]').value = text
        cell.querySelector('[data-sg-editor]').dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
    }

    return { component, calls, edit, cell }
}

test('without toggleable the component is always active', () => {
    assert.equal(setup({ toggleable: false }).component.active, true)
})

test('a toggleable grid starts as configured', () => {
    assert.equal(setup({ active: false }).component.active, false)
    assert.equal(setup({ active: true }).component.active, true)
})

test('turning the mode on calls the server and flips the flag', async () => {
    const g = setup()

    await g.component.toggle()

    assert.deepEqual(g.calls, [true])
    assert.equal(g.component.active, true)
    assert.equal(g.component.switching, false)
})

test('leaving a clean grid needs no confirmation', async () => {
    let asked = 0
    const g = setup({ active: true, confirm: () => (asked++, true) })

    await g.component.toggle()

    assert.equal(asked, 0)
    assert.deepEqual(g.calls, [false])
    assert.equal(g.component.active, false)
})

test('leaving with unsaved edits asks first; refusing keeps the mode and the edits', async () => {
    const asked = []
    const g = setup({ active: true, confirm: (text) => (asked.push(text), false) })

    g.edit('Changed')
    assert.equal(g.component.dirty, 1)

    await g.component.toggle()

    assert.deepEqual(asked, ['Leave?'])
    assert.deepEqual(g.calls, [])
    assert.equal(g.component.active, true)
    assert.equal(g.component.dirty, 1)
})

test('confirming discards the edits and switches the mode off', async () => {
    const g = setup({ active: true, confirm: () => true })

    g.edit('Changed')
    await g.component.toggle()

    assert.equal(g.component.dirty, 0)
    assert.deepEqual(g.calls, [false])
    assert.equal(g.component.active, false)
})
