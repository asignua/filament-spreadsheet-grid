/**
 * Clipboard helpers: TSV parsing/serialising (Excel, Google Sheets, LibreOffice) and
 * coercion of a pasted string to a typed cell value. Pure functions, no DOM.
 */

/**
 * Parse tab-separated text the way spreadsheets write it to the clipboard.
 * Cells holding tabs, line breaks or quotes arrive wrapped in double quotes, with inner
 * quotes doubled. A single trailing line break (every spreadsheet adds one) is dropped.
 *
 * @param {string} text
 * @returns {string[][]}
 */
export function parseTsv(text) {
    if (text === '' || text === null || text === undefined) {
        return []
    }

    const rows = []
    let row = []
    let cell = ''
    let quoted = false
    let cellStart = true

    const input = String(text)

    for (let i = 0; i < input.length; i++) {
        const char = input[i]

        if (quoted) {
            if (char === '"') {
                if (input[i + 1] === '"') {
                    cell += '"'
                    i++
                } else {
                    quoted = false
                }
            } else {
                cell += char
            }

            continue
        }

        if (char === '"' && cellStart) {
            quoted = true
            cellStart = false

            continue
        }

        if (char === '\t') {
            row.push(cell)
            cell = ''
            cellStart = true

            continue
        }

        if (char === '\r' || char === '\n') {
            if (char === '\r' && input[i + 1] === '\n') {
                i++
            }

            row.push(cell)
            rows.push(row)
            row = []
            cell = ''
            cellStart = true

            continue
        }

        cell += char
        cellStart = false
    }

    // The last line has no terminator unless the text ended with one.
    if (cell !== '' || row.length > 0 || quoted) {
        row.push(cell)
        rows.push(row)
    }

    return rows
}

/**
 * @param {Array<Array<string|number|null|undefined>>} rows
 * @returns {string}
 */
export function serializeTsv(rows) {
    return rows
        .map((row) =>
            row
                .map((value) => {
                    const cell = value === null || value === undefined ? '' : String(value)

                    return /[\t\r\n"]/.test(cell) ? '"' + cell.replace(/"/g, '""') + '"' : cell
                })
                .join('\t'),
        )
        .join('\n')
}

/**
 * "1 250,5" / "1'250.5" / "1 250,5" -> "1250.5". Does not validate.
 *
 * @param {string} value
 * @returns {string}
 */
export function normalizeNumber(value) {
    let text = String(value ?? '').replace(/[\s  ']/g, '')

    // "1.250,50": the last separator is the decimal one, the other is a thousands mark.
    if (text.includes(',') && text.includes('.')) {
        text =
            text.lastIndexOf(',') > text.lastIndexOf('.')
                ? text.replace(/\./g, '').replace(',', '.')
                : text.replace(/,/g, '')
    } else {
        text = text.replace(',', '.')
    }

    return text
}

/**
 * "1,250" / "1.000": one separator before exactly three digits reads as thousands in one locale
 * and as decimals in another, and guessing wrong is a silent factor of 1000. Spaces and
 * apostrophes are thousands marks only, so they do not make a value ambiguous. Same rule as
 * GridCellValue::isAmbiguousNumber() on the server.
 *
 * @param {string} value
 * @param {boolean} [dotIsDecimal] the value is in the machine form, where a dot is the decimal point
 * @returns {boolean}
 */
export function isAmbiguousNumber(value, dotIsDecimal = false) {
    const text = String(value ?? '').replace(/[\s\u00a0\u202f']/g, '')

    return (dotIsDecimal ? /^-?[1-9]\d{0,2},\d{3}$/ : /^-?[1-9]\d{0,2}[.,]\d{3}$/).test(text)
}

const TRUE_WORDS = ['1', 'true', 'yes', 'y', 'on', 'x', '✓', '✔', 'так', 'да', 'ja', 'oui', 'si', 'sí', 'tak', 'evet', 'sim', 'ja']
const FALSE_WORDS = ['0', 'false', 'no', 'n', 'off', '', '✗', '✘', 'ні', 'нет', 'nein', 'non', 'nee', 'nie', 'hayır', 'não']

/**
 * Accepts ISO (2026-10-02), 02.10.2026, 02/10/2026, 2/10/26 (day first, like the rest of
 * the world; US order is ambiguous and not guessed) and returns ISO or null.
 *
 * @param {string} value
 * @returns {string|null}
 */
export function parseDate(value) {
    const text = String(value ?? '').trim()

    let year
    let month
    let day

    let match = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/.exec(text)

    if (match) {
        ;[, year, month, day] = match
    } else if ((match = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4}|\d{2})$/.exec(text))) {
        ;[, day, month, year] = match

        if (year.length === 2) {
            year = (Number(year) < 70 ? '20' : '19') + year
        }
    } else {
        return null
    }

    const y = Number(year)
    const m = Number(month)
    const d = Number(day)
    const probe = new Date(Date.UTC(y, m - 1, d))

    if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== m - 1 || probe.getUTCDate() !== d) {
        return null
    }

    return String(y).padStart(4, '0') + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0')
}

/**
 * Turn pasted/typed text into the canonical string kept in the change set.
 *   text    -> the text
 *   number  -> normalised number string ("" stays "")
 *   boolean -> "1" / "0"
 *   date    -> ISO date
 *   select  -> the option value, matched by value, then by label (case-insensitive)
 *
 * @param {{type: string, options?: Array<{value: string, label: string}>}} column
 * @param {string} raw
 * @param {{canonical?: boolean}} [options] canonical: the text is a value the grid itself holds
 *        (an unchanged edit, a fill, its own copy), so a dot in a number is the decimal point
 * @returns {{ok: boolean, value: string}}  ok=false means the text is not valid for this type
 */
export function coerceValue(column, raw, { canonical = false } = {}) {
    const text = raw === null || raw === undefined ? '' : String(raw)

    switch (column.type) {
        case 'number':
        case 'integer': {
            // Integers have no machine form with three decimals, so there "1.000" stays ambiguous.
            if (isAmbiguousNumber(text, canonical && column.type === 'number')) {
                return { ok: false, value: text }
            }

            const value = normalizeNumber(text)

            return { ok: value === '' || /^-?\d+(\.\d+)?$/.test(value), value }
        }

        case 'boolean': {
            const word = text.trim().toLowerCase()

            if (TRUE_WORDS.includes(word) && word !== '') {
                return { ok: true, value: '1' }
            }

            return FALSE_WORDS.includes(word) ? { ok: true, value: '0' } : { ok: false, value: text }
        }

        case 'date': {
            if (text.trim() === '') {
                return { ok: true, value: '' }
            }

            const iso = parseDate(text)

            return iso === null ? { ok: false, value: text } : { ok: true, value: iso }
        }

        case 'select': {
            const needle = text.trim().toLowerCase()

            if (needle === '') {
                return { ok: true, value: '' }
            }

            const options = column.options ?? []
            const hit =
                options.find((option) => String(option.value).toLowerCase() === needle) ??
                options.find((option) => String(option.label).toLowerCase() === needle)

            return hit ? { ok: true, value: String(hit.value) } : { ok: false, value: text }
        }

        default:
            return { ok: true, value: text }
    }
}

/**
 * What a cell shows (and what Copy puts on the clipboard) for a stored value.
 *
 * @param {{type: string, options?: Array<{value: string, label: string}>}} column
 * @param {string} value
 * @param {{forClipboard?: boolean}} [options]
 * @returns {string}
 */
export function displayValue(column, value, { forClipboard = false } = {}) {
    const text = value === null || value === undefined ? '' : String(value)

    switch (column.type) {
        case 'boolean':
            return forClipboard ? (text === '1' ? 'TRUE' : 'FALSE') : text === '1' ? '✓' : '✗'

        case 'select': {
            const hit = (column.options ?? []).find((option) => String(option.value) === text)

            return hit ? String(hit.label) : text
        }

        case 'date':
            return forClipboard ? text : formatDate(text, column.dateFormat)

        default:
            return text
    }
}

/**
 * Format an ISO date with the PHP-style tokens d, m, Y, y (the rest is copied as is).
 * Anything that is not an ISO date is returned untouched, so a half-typed value stays visible.
 *
 * @param {string} iso
 * @param {string} [format]
 * @returns {string}
 */
export function formatDate(iso, format = 'Y-m-d') {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)

    if (!match || format === 'Y-m-d') {
        return iso
    }

    const [, year, month, day] = match

    return format.replace(/[dmYy]/g, (token) => ({ d: day, m: month, Y: year, y: year.slice(2) })[token])
}
