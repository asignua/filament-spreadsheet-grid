/**
 * The set of edited-but-unsaved cells, plus the per-cell validation errors.
 * Values are canonical strings (see coerceValue()). A cell edited back to its original
 * value stops being dirty. Pure, no DOM.
 */

export const ROW_FIELD = '*'

const norm = (value) => (value === null || value === undefined ? '' : String(value))

export class ChangeSet {
    constructor() {
        /** @type {Map<string, Map<string, {value: string, original: string}>>} */
        this.rows = new Map()
        /** @type {Map<string, Map<string, string[]>>} */
        this.errors = new Map()
    }

    /**
     * @returns {boolean} whether the cell is dirty afterwards
     */
    set(key, field, value, original) {
        const next = norm(value)
        const before = norm(original)

        if (next === before) {
            this.#remove(key, field)

            return false
        }

        if (!this.rows.has(key)) {
            this.rows.set(key, new Map())
        }

        this.rows.get(key).set(field, { value: next, original: before })

        return true
    }

    get(key, field) {
        return this.rows.get(key)?.get(field)
    }

    isDirty(key, field) {
        return this.rows.get(key)?.has(field) ?? false
    }

    /** Number of dirty cells. */
    get size() {
        let count = 0

        for (const fields of this.rows.values()) {
            count += fields.size
        }

        return count
    }

    get rowCount() {
        return this.rows.size
    }

    get errorCount() {
        let count = 0

        for (const fields of this.errors.values()) {
            count += fields.size
        }

        return count
    }

    /**
     * @returns {Object<string, Object<string, string>>}  { recordKey: { field: value } }
     */
    toPayload() {
        const payload = {}

        for (const [key, fields] of this.rows) {
            payload[key] = {}

            for (const [field, entry] of fields) {
                payload[key][field] = entry.value
            }
        }

        return payload
    }

    setError(key, field, messages) {
        const list = Array.isArray(messages) ? messages : [String(messages)]

        if (!this.errors.has(key)) {
            this.errors.set(key, new Map())
        }

        this.errors.get(key).set(field, list)
    }

    clearError(key, field) {
        const fields = this.errors.get(key)

        if (!fields) {
            return
        }

        fields.delete(field)

        if (fields.size === 0) {
            this.errors.delete(key)
        }
    }

    errorFor(key, field) {
        return this.errors.get(key)?.get(field) ?? this.errors.get(key)?.get(ROW_FIELD) ?? null
    }

    clearErrors() {
        this.errors.clear()
    }

    clear() {
        this.rows.clear()
        this.errors.clear()
    }

    /**
     * Apply a server answer `{saved: [key], errors: {key: {field: [messages]}}}` for the
     * payload that was sent: saved rows are forgotten, failed cells keep their value and
     * get the message, every other sent cell loses a stale error.
     *
     * @param {Object<string, Object<string, string>>} sent
     * @param {{saved?: string[], errors?: Object<string, Object<string, string[]>>}} result
     */
    applyResult(sent, result) {
        const saved = new Set((result.saved ?? []).map(String))
        const errors = result.errors ?? {}

        for (const key of Object.keys(sent)) {
            this.errors.delete(key)

            if (saved.has(key)) {
                // Keep what was edited again while the request was in flight.
                for (const [field, value] of Object.entries(sent[key])) {
                    if (this.get(key, field)?.value === value) {
                        this.#remove(key, field)
                    }
                }
            }
        }

        for (const [key, fields] of Object.entries(errors)) {
            for (const [field, messages] of Object.entries(fields)) {
                this.setError(key, field, messages)
            }
        }
    }

    #remove(key, field) {
        const fields = this.rows.get(key)

        if (!fields) {
            return
        }

        fields.delete(field)

        if (fields.size === 0) {
            this.rows.delete(key)
        }
    }
}
