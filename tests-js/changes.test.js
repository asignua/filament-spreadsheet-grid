import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ChangeSet, ROW_FIELD } from '../resources/js/changes.js'

test('a cell edited back to its original value stops being dirty', () => {
    const changes = new ChangeSet()

    assert.equal(changes.set('1', 'name', 'B', 'A'), true)
    assert.equal(changes.size, 1)
    assert.equal(changes.set('1', 'name', 'A', 'A'), false)
    assert.equal(changes.size, 0)
    assert.equal(changes.rowCount, 0)
})

test('null and empty string are the same original', () => {
    const changes = new ChangeSet()

    assert.equal(changes.set('1', 'note', '', null), false)
})

test('toPayload groups by record key', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')
    changes.set('1', 'price', '5', '4')
    changes.set('2', 'name', 'Z', 'Y')

    assert.deepEqual(changes.toPayload(), { 1: { name: 'B', price: '5' }, 2: { name: 'Z' } })
    assert.equal(changes.rowCount, 2)
})

test('applyResult forgets saved rows and records errors on the rest', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')
    changes.set('2', 'price', 'x', '4')

    const sent = changes.toPayload()

    changes.applyResult(sent, { saved: ['1'], errors: { 2: { price: ['Not a number'] } } })

    assert.equal(changes.isDirty('1', 'name'), false)
    assert.equal(changes.isDirty('2', 'price'), true)
    assert.deepEqual(changes.errorFor('2', 'price'), ['Not a number'])
    assert.equal(changes.errorCount, 1)
})

test('applyResult keeps a cell edited again while the request was in flight', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')

    const sent = changes.toPayload()

    changes.set('1', 'name', 'C', 'A')
    changes.applyResult(sent, { saved: ['1'] })

    assert.equal(changes.get('1', 'name').value, 'C')
})

test('a stale error disappears on the next answer; a row error covers all cells', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')
    changes.setError('1', 'name', ['old'])
    changes.applyResult(changes.toPayload(), { errors: { 1: { [ROW_FIELD]: ['Not allowed'] } } })

    assert.deepEqual(changes.errorFor('1', 'name'), ['Not allowed'])
    assert.deepEqual(changes.errorFor('1', 'anything'), ['Not allowed'])
})

test('a cell edited again while its save is in flight gets the saved value as its original', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')

    const sent = changes.toPayload()

    changes.markInFlight(sent)
    // The page still shows A: the edit is captured against that stale original.
    changes.set('1', 'name', 'C', 'A')
    changes.applyResult(sent, { saved: ['1'] })

    assert.equal(changes.get('1', 'name').value, 'C')
    assert.deepEqual(changes.toOriginals(), { 1: { name: 'B' } })
})

test('editing back to the loaded value while a save is in flight still undoes that save', () => {
    const changes = new ChangeSet()

    changes.set('1', 'name', 'B', 'A')

    const sent = changes.toPayload()

    changes.markInFlight(sent)
    assert.equal(changes.set('1', 'name', 'A', 'A'), true)
    changes.applyResult(sent, { saved: ['1'] })

    assert.deepEqual(changes.toPayload(), { 1: { name: 'A' } })
    assert.deepEqual(changes.toOriginals(), { 1: { name: 'B' } })
})

test('if the in-flight save is refused or fails, editing back to the loaded value is clean again', () => {
    const refused = new ChangeSet()

    refused.set('1', 'name', 'B', 'A')
    let sent = refused.toPayload()
    refused.markInFlight(sent)
    refused.set('1', 'name', 'A', 'A')
    refused.applyResult(sent, { errors: { 1: { name: ['No'] } } })
    assert.equal(refused.isDirty('1', 'name'), false)

    const failed = new ChangeSet()

    failed.set('1', 'name', 'B', 'A')
    sent = failed.toPayload()
    failed.markInFlight(sent)
    failed.set('1', 'name', 'A', 'A')
    failed.abortInFlight()
    assert.equal(failed.isDirty('1', 'name'), false)
})
