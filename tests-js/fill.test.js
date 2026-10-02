import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fillDown, fillRight, inRect, pastePlan, rectOf } from '../resources/js/fill.js'

const grid = [
    ['a0', 'b0', 'c0'],
    ['a1', 'b1', 'c1'],
    ['a2', 'b2', 'c2'],
    ['a3', 'b3', 'c3'],
]
const read = (row, col) => grid[row][col]

test('rectOf normalises corners in any order', () => {
    assert.deepEqual(rectOf({ row: 3, col: 2 }, { row: 1, col: 0 }), { top: 1, left: 0, bottom: 3, right: 2 })
    assert.equal(inRect(rectOf({ row: 1, col: 1 }, { row: 2, col: 2 }), 2, 1), true)
    assert.equal(inRect(rectOf({ row: 1, col: 1 }, { row: 2, col: 2 }), 3, 1), false)
    assert.equal(inRect(null, 0, 0), false)
})

test('fillDown copies the first row of the selection into the rows below, per column', () => {
    const plan = fillDown({ top: 1, left: 0, bottom: 3, right: 1 }, read)

    assert.deepEqual(plan, [
        { row: 2, col: 0, value: 'a1' },
        { row: 3, col: 0, value: 'a1' },
        { row: 2, col: 1, value: 'b1' },
        { row: 3, col: 1, value: 'b1' },
    ])
})

test('fillDown on a single row copies from the row above; nothing on the first row', () => {
    assert.deepEqual(fillDown({ top: 2, left: 1, bottom: 2, right: 2 }, read), [
        { row: 2, col: 1, value: 'b1' },
        { row: 2, col: 2, value: 'c1' },
    ])
    assert.deepEqual(fillDown({ top: 0, left: 0, bottom: 0, right: 2 }, read), [])
})

test('fillRight mirrors fillDown', () => {
    assert.deepEqual(fillRight({ top: 0, left: 0, bottom: 1, right: 2 }, read), [
        { row: 0, col: 1, value: 'a0' },
        { row: 0, col: 2, value: 'a0' },
        { row: 1, col: 1, value: 'a1' },
        { row: 1, col: 2, value: 'a1' },
    ])
    assert.deepEqual(fillRight({ top: 1, left: 2, bottom: 2, right: 2 }, read), [
        { row: 1, col: 2, value: 'b1' },
        { row: 2, col: 2, value: 'b2' },
    ])
    assert.deepEqual(fillRight({ top: 1, left: 0, bottom: 1, right: 0 }, read), [])
})

test('pastePlan anchors at the top-left corner and clips to the grid', () => {
    const plan = pastePlan([['1', '2', '3'], ['4', '5', '6']], { top: 3, left: 1, bottom: 3, right: 1 }, { rows: 4, cols: 3 })

    assert.deepEqual(plan, [
        { row: 3, col: 1, value: '1' },
        { row: 3, col: 2, value: '2' },
    ])
})

test('a single pasted cell fills the whole selection', () => {
    const plan = pastePlan([['x']], { top: 0, left: 0, bottom: 1, right: 1 }, { rows: 4, cols: 3 })

    assert.equal(plan.length, 4)
    assert.ok(plan.every((item) => item.value === 'x'))
})

test('pastePlan of an empty clipboard is empty', () => {
    assert.deepEqual(pastePlan([], { top: 0, left: 0, bottom: 0, right: 0 }, { rows: 1, cols: 1 }), [])
})
