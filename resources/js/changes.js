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
        /**
         * The payload of the save request in flight, if any: until it answers, the page still
         * shows the old values, so "back to the original" may not be "back to what is stored".
         *
         * @type {Object<string, Object<string, string>>|null}
         */
        this.inFlight = null
    }

    /**
     * @returns {boolean} whether the cell is dirty afterwards
     */
    set(key, field, value, original) {
        const next = norm(value)
        const before = norm(original)

        // Edited back to the loaded value while a different one is being saved: that is
        // still a change (it has to undo the save), so it is kept.
        const sending = this.inFlight?.[key]?.[field]

        if (next === before && (sending === undefined || norm(sending) === next)) {
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

    /**
     * What the client loaded for every dirty cell, so the server can spot a value someone
     * else changed in the meantime.
     *
     * @returns {Object<string, Object<string, string>>}  { recordKey: { field: original } }
     */
    toOriginals() {
        const originals = {}

        for (const [key, fields] of this.rows) {
            originals[key] = {}

            for (const [field, entry] of fields) {
                originals[key][field] = entry.original
            }
        }

        return originals
    }

    /** Remember the payload of the request that is about to go out (see set()). */
    markInFlight(sent) {
        this.inFlight = sent
    }

    /**
     * The request failed: nothing was stored, so a cell edited back to its loaded value
     * meanwhile is clean again.
     */
    abortInFlight() {
        const sent = this.inFlight ?? {}

        this.inFlight = null

        for (const [key, fields] of Object.entries(sent)) {
            for (const field of Object.keys(fields)) {
                const entry = this.get(key, field)

                if (entry && entry.value === entry.original) {
                    this.#remove(key, field)
                }
            }
        }
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
        this.inFlight = null
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

        this.inFlight = null

        for (const key of Object.keys(sent)) {
            this.errors.delete(key)

            for (const [field, value] of Object.entries(sent[key])) {
                const entry = this.get(key, field)

                if (!entry) {
                    continue
                }

                if (saved.has(key)) {
                    // The stored value is now what was sent: forget the cell, or, when it was
                    // edited again in the meantime, move its original forward so the next save
                    // is not refused as a conflict with the user's own change.
                    entry.original = value
                }

                if (entry.value === entry.original) {
                    this.#remove(key, field)
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
