import assert from 'node:assert/strict'
import { test } from 'node:test'
import { initialState, reduce } from '../resources/js/navigation.js'

const dims = { rows: 3, cols: 3 }
const key = (state, k, extra = {}) => reduce(state, { type: 'key', key: k, ...extra }, dims)
const on = (row, col) => ({ mode: 'nav', anchor: { row, col }, focus: { row, col } })

test('with nothing selected, an arrow lands on the first cell', () => {
    const { state, handled } = key(initialState(), 'ArrowDown')

    assert.deepEqual(state.focus, { row: 0, col: 0 })
    assert.equal(handled, true)
})

test('arrows move one cell and stop at the edges', () => {
    assert.deepEqual(key(on(1, 1), 'ArrowRight').state.focus, { row: 1, col: 2 })
    assert.deepEqual(key(on(0, 0), 'ArrowUp').state.focus, { row: 0, col: 0 })
    assert.deepEqual(key(on(2, 2), 'ArrowDown').state.focus, { row: 2, col: 2 })
})

test('shift+arrow extends the selection from the anchor, plain arrow collapses it', () => {
    const extended = key(on(1, 1), 'ArrowRight', { shift: true }).state

    assert.deepEqual(extended.anchor, { row: 1, col: 1 })
    assert.deepEqual(extended.focus, { row: 1, col: 2 })

    const collapsed = key(extended, 'ArrowDown').state

    assert.deepEqual(collapsed.anchor, collapsed.focus)
})

test('ctrl+arrow jumps to the edge', () => {
    assert.deepEqual(key(on(1, 1), 'ArrowRight', { ctrl: true }).state.focus, { row: 1, col: 2 })
    assert.deepEqual(key(on(1, 1), 'ArrowUp', { ctrl: true }).state.focus, { row: 0, col: 1 })
})

test('Tab walks right and wraps to the next row; Shift+Tab walks back', () => {
    assert.deepEqual(key(on(0, 2), 'Tab').state.focus, { row: 1, col: 0 })
    assert.deepEqual(key(on(1, 0), 'Tab', { shift: true }).state.focus, { row: 0, col: 2 })
})

test('Tab past the last cell is left to the browser', () => {
    const { handled, state } = key(on(2, 2), 'Tab')

    assert.equal(handled, false)
    assert.deepEqual(state.focus, { row: 2, col: 2 })
    assert.equal(key(on(0, 0), 'Tab', { shift: true }).handled, false)
})

test('Enter and F2 start editing', () => {
    const entered = key(on(1, 1), 'Enter')

    assert.equal(entered.state.mode, 'edit')
    assert.deepEqual(entered.effects, [{ type: 'startEdit' }])
    assert.equal(key(on(1, 1), 'F2').state.mode, 'edit')
})

test('a printable character starts editing and replaces the content', () => {
    const { state, effects } = key(on(0, 0), 'x')

    assert.equal(state.mode, 'edit')
    assert.deepEqual(effects, [{ type: 'startEdit', replace: true, char: 'x' }])
    assert.equal(key(on(0, 0), 'x', { ctrl: true }).effects.length, 0)
})

test('Enter in the editor commits and moves down; Shift+Enter moves up', () => {
    const editing = { ...on(1, 1), mode: 'edit' }
    const down = key(editing, 'Enter')

    assert.deepEqual(down.effects, [{ type: 'commit', cell: { row: 1, col: 1 } }])
    assert.equal(down.state.mode, 'nav')
    assert.deepEqual(down.state.focus, { row: 2, col: 1 })
    assert.deepEqual(key(editing, 'Enter', { shift: true }).state.focus, { row: 0, col: 1 })
})

test('Enter on the last row commits and stays', () => {
    const { state } = key({ ...on(2, 0), mode: 'edit' }, 'Enter')

    assert.deepEqual(state.focus, { row: 2, col: 0 })
})

test('Tab in the editor commits and moves right', () => {
    const { effects, state } = key({ ...on(0, 0), mode: 'edit' }, 'Tab')

    assert.equal(effects[0].type, 'commit')
    assert.deepEqual(state.focus, { row: 0, col: 1 })
})

test('Escape in the editor cancels and keeps the cell; arrows belong to the editor', () => {
    const editing = { ...on(1, 1), mode: 'edit' }
    const escaped = key(editing, 'Escape')

    assert.deepEqual(escaped.effects, [{ type: 'cancel', cell: { row: 1, col: 1 } }])
    assert.equal(escaped.state.mode, 'nav')
    assert.equal(key(editing, 'ArrowLeft').handled, false)
})

test('Escape in navigation collapses the selection', () => {
    const range = { mode: 'nav', anchor: { row: 0, col: 0 }, focus: { row: 2, col: 2 } }

    assert.deepEqual(key(range, 'Escape').state.anchor, { row: 2, col: 2 })
})

test('Delete clears, Ctrl+A selects all, Ctrl+D / Ctrl+R fill', () => {
    assert.deepEqual(key(on(1, 1), 'Delete').effects, [{ type: 'clear' }])
    assert.deepEqual(key(on(1, 1), 'Backspace').effects, [{ type: 'clear' }])

    const all = key(on(1, 1), 'a', { ctrl: true }).state

    assert.deepEqual(all.anchor, { row: 0, col: 0 })
    assert.deepEqual(all.focus, { row: 2, col: 2 })
    assert.deepEqual(key(on(1, 1), 'd', { ctrl: true }).effects, [{ type: 'fillDown' }])
    assert.deepEqual(key(on(1, 1), 'r', { ctrl: true }).effects, [{ type: 'fillRight' }])
})

test('Ctrl+C / V are left to the clipboard events', () => {
    assert.equal(key(on(1, 1), 'c', { ctrl: true }).handled, false)
    assert.equal(key(on(1, 1), 'v', { ctrl: true }).handled, false)
})

test('Home / End move within the row, with ctrl to the corners', () => {
    assert.deepEqual(key(on(1, 1), 'Home').state.focus, { row: 1, col: 0 })
    assert.deepEqual(key(on(1, 1), 'End').state.focus, { row: 1, col: 2 })
    assert.deepEqual(key(on(1, 1), 'End', { ctrl: true }).state.focus, { row: 2, col: 2 })
})

test('mouse: select, shift-select and drag build a rectangle; a click commits an open editor', () => {
    const first = reduce(initialState(), { type: 'select', row: 0, col: 0 }, dims).state
    const dragged = reduce(first, { type: 'drag', row: 2, col: 1 }, dims).state

    assert.deepEqual(dragged.anchor, { row: 0, col: 0 })
    assert.deepEqual(dragged.focus, { row: 2, col: 1 })

    const shifted = reduce(on(1, 1), { type: 'select', row: 2, col: 2, extend: true }, dims).state

    assert.deepEqual(shifted.anchor, { row: 1, col: 1 })

    const away = reduce({ ...on(1, 1), mode: 'edit' }, { type: 'select', row: 0, col: 0 }, dims)

    assert.deepEqual(away.effects, [{ type: 'commit', cell: { row: 1, col: 1 } }])
    assert.equal(away.state.mode, 'nav')
})

test('blur commits an open editor without moving', () => {
    const { effects, state } = reduce({ ...on(1, 1), mode: 'edit' }, { type: 'blur' }, dims)

    assert.deepEqual(effects, [{ type: 'commit', cell: { row: 1, col: 1 }, blur: true }])
    assert.deepEqual(state.focus, { row: 1, col: 1 })
    assert.equal(reduce(on(1, 1), { type: 'blur' }, dims).handled, false)
})

test('an empty grid handles nothing', () => {
    assert.equal(reduce(initialState(), { type: 'key', key: 'ArrowDown' }, { rows: 0, cols: 0 }).handled, false)
})
