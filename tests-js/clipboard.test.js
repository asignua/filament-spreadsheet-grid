import assert from 'node:assert/strict'
import { test } from 'node:test'
import { coerceValue, displayValue, formatDate, isAmbiguousNumber, normalizeNumber, parseDate, parseTsv, serializeTsv } from '../resources/js/clipboard.js'

test('parseTsv splits a plain block and drops the trailing line break', () => {
    assert.deepEqual(parseTsv('a\tb\nc\td\n'), [['a', 'b'], ['c', 'd']])
    assert.deepEqual(parseTsv('a\tb\r\nc\td\r\n'), [['a', 'b'], ['c', 'd']])
})

test('parseTsv keeps empty cells and a last line without terminator', () => {
    assert.deepEqual(parseTsv('a\t\tc\n\td'), [['a', '', 'c'], ['', 'd']])
    assert.deepEqual(parseTsv('single'), [['single']])
})

test('parseTsv understands quoted cells with tabs, line breaks and doubled quotes', () => {
    assert.deepEqual(parseTsv('"a\tb"\t"line1\nline2"\t"say ""hi"""\n'), [['a\tb', 'line1\nline2', 'say "hi"']])
})

test('parseTsv does not treat a quote in the middle of a cell as a quoted cell', () => {
    assert.deepEqual(parseTsv('5" nail\tx'), [['5" nail', 'x']])
})

test('parseTsv of nothing is no rows', () => {
    assert.deepEqual(parseTsv(''), [])
})

test('serializeTsv quotes only what needs it and round-trips', () => {
    const rows = [['a', 'b\tc', 'multi\nline'], ['say "hi"', '', null]]
    const text = serializeTsv(rows)

    assert.equal(text, 'a\t"b\tc"\t"multi\nline"\n"say ""hi"""\t\t')
    assert.deepEqual(parseTsv(text), [['a', 'b\tc', 'multi\nline'], ['say "hi"', '', '']])
})

test('normalizeNumber handles spaces, nbsp, comma and thousands marks', () => {
    assert.equal(normalizeNumber('1 250,5'), '1250.5')
    assert.equal(normalizeNumber('1 250,50'), '1250.50')
    assert.equal(normalizeNumber("1'250.5"), '1250.5')
    assert.equal(normalizeNumber('1.250,50'), '1250.50')
    assert.equal(normalizeNumber('1,250.50'), '1250.50')
    assert.equal(normalizeNumber('-3,2'), '-3.2')
})

test('one separator before exactly three digits is ambiguous and is not guessed', () => {
    for (const text of ['1,000', '1.000', '1,250', '1.250', '-12,500', '250.000', '1 ,000']) {
        assert.equal(isAmbiguousNumber(text), true, text)
        assert.equal(coerceValue({ type: 'number' }, text).ok, false, text)
        assert.equal(coerceValue({ type: 'integer' }, text).ok, false, text)
    }

    for (const text of ['0,125', '1,25', '1,2500', '1 000', '1.000,00', '1,000.50', '1000', '12345,678']) {
        assert.equal(isAmbiguousNumber(text), false, text)
    }

    assert.deepEqual(coerceValue({ type: 'number' }, '0,125'), { ok: true, value: '0.125' })
    assert.deepEqual(coerceValue({ type: 'integer' }, '1 000'), { ok: true, value: '1000' })
    assert.deepEqual(coerceValue({ type: 'number' }, '1.000,00'), { ok: true, value: '1000.00' })
})

test('a value already in the machine form keeps its dot as the decimal point', () => {
    assert.deepEqual(coerceValue({ type: 'number' }, '1.250', { canonical: true }), { ok: true, value: '1.250' })
    // A comma never appears in the machine form, so it stays ambiguous.
    assert.equal(coerceValue({ type: 'number' }, '1,250', { canonical: true }).ok, false)
})

test('parseDate accepts ISO and day-first formats, rejects impossible dates', () => {
    assert.equal(parseDate('2026-10-02'), '2026-10-02')
    assert.equal(parseDate('02.10.2026'), '2026-10-02')
    assert.equal(parseDate('2/10/2026'), '2026-10-02')
    assert.equal(parseDate('2.10.26'), '2026-10-02')
    assert.equal(parseDate('31.02.2026'), null)
    assert.equal(parseDate('soon'), null)
})

test('coerceValue by type', () => {
    assert.deepEqual(coerceValue({ type: 'number' }, '1 250,5'), { ok: true, value: '1250.5' })
    assert.deepEqual(coerceValue({ type: 'number' }, 'abc'), { ok: false, value: 'abc' })
    assert.deepEqual(coerceValue({ type: 'number' }, ''), { ok: true, value: '' })
    assert.deepEqual(coerceValue({ type: 'boolean' }, 'TRUE'), { ok: true, value: '1' })
    assert.deepEqual(coerceValue({ type: 'boolean' }, 'так'), { ok: true, value: '1' })
    assert.deepEqual(coerceValue({ type: 'boolean' }, 'FALSE'), { ok: true, value: '0' })
    assert.equal(coerceValue({ type: 'boolean' }, 'maybe').ok, false)
    assert.deepEqual(coerceValue({ type: 'date' }, '02.10.2026'), { ok: true, value: '2026-10-02' })
    assert.equal(coerceValue({ type: 'date' }, 'x').ok, false)
    assert.deepEqual(coerceValue({ type: 'text' }, ' keep '), { ok: true, value: ' keep ' })
})

test('coerceValue matches a select by value, then by label, case-insensitively', () => {
    const column = { type: 'select', options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }] }

    assert.deepEqual(coerceValue(column, 'b'), { ok: true, value: 'b' })
    assert.deepEqual(coerceValue(column, 'ALPHA'), { ok: true, value: 'a' })
    assert.equal(coerceValue(column, 'gamma').ok, false)
    assert.deepEqual(coerceValue(column, ''), { ok: true, value: '' })
})

test('displayValue shows labels and ticks, clipboard form uses TRUE/FALSE and ISO dates', () => {
    const select = { type: 'select', options: [{ value: 'a', label: 'Alpha' }] }

    assert.equal(displayValue(select, 'a'), 'Alpha')
    assert.equal(displayValue({ type: 'boolean' }, '1'), '✓')
    assert.equal(displayValue({ type: 'boolean' }, '1', { forClipboard: true }), 'TRUE')
    assert.equal(displayValue({ type: 'date', dateFormat: 'd.m.Y' }, '2026-10-02'), '02.10.2026')
    assert.equal(displayValue({ type: 'date', dateFormat: 'd.m.Y' }, '2026-10-02', { forClipboard: true }), '2026-10-02')
    assert.equal(formatDate('bad', 'd.m.Y'), 'bad')
})
