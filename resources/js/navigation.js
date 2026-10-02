/**
 * The keyboard/mouse state machine of the grid. A pure reducer: it gets the current state,
 * an action and the grid size, and returns the next state plus the effects the DOM layer
 * must perform. No DOM here, which is what makes it unit-testable.
 *
 * State:   { mode: 'nav' | 'edit', anchor: Cell|null, focus: Cell|null }
 *          `focus` is the active cell (the one that edits); `anchor` is the other corner of
 *          the selection. They are equal when a single cell is selected.
 * Actions: { type: 'key', key, shift?, ctrl?, alt? }
 *          { type: 'select', row, col, extend? }      mouse down on a cell
 *          { type: 'drag', row, col }                 mouse moved over a cell with the button down
 *          { type: 'blur' }                           the editor lost focus
 * Effects: startEdit {replace?, char?} | commit {cell, move?} | cancel {cell} | clear
 *          | selectAll | fillDown | fillRight
 * Result:  { state, effects, handled }  `handled: false` = let the browser do its thing.
 */

/** @typedef {{row: number, col: number}} Cell */

export function initialState() {
    return { mode: 'nav', anchor: null, focus: null }
}

const clamp = (value, min, max) => Math.max(min, Math.min(max, value))

function at(state, row, col, dims, extend = false) {
    const cell = { row: clamp(row, 0, dims.rows - 1), col: clamp(col, 0, dims.cols - 1) }

    return {
        mode: 'nav',
        focus: cell,
        anchor: extend && state.anchor ? state.anchor : cell,
    }
}

function result(state, effects = [], handled = true) {
    return { state, effects, handled }
}

/**
 * Next cell for Tab: right, wrapping to the first column of the next row. Returns null past
 * the very last (or before the very first) cell so the browser can take focus elsewhere.
 */
function tabTarget(focus, dims, backwards) {
    if (!backwards) {
        if (focus.col + 1 < dims.cols) {
            return { row: focus.row, col: focus.col + 1 }
        }

        return focus.row + 1 < dims.rows ? { row: focus.row + 1, col: 0 } : null
    }

    if (focus.col > 0) {
        return { row: focus.row, col: focus.col - 1 }
    }

    return focus.row > 0 ? { row: focus.row - 1, col: dims.cols - 1 } : null
}

const ARROWS = {
    ArrowUp: [-1, 0],
    ArrowDown: [1, 0],
    ArrowLeft: [0, -1],
    ArrowRight: [0, 1],
}

/**
 * @param {{mode: string, anchor: Cell|null, focus: Cell|null}} state
 * @param {object} action
 * @param {{rows: number, cols: number}} dims
 */
export function reduce(state, action, dims) {
    if (dims.rows === 0 || dims.cols === 0) {
        return result(state, [], false)
    }

    switch (action.type) {
        case 'select': {
            // A click while editing commits the edit first (the cell we leave).
            const effects = state.mode === 'edit' && state.focus ? [{ type: 'commit', cell: state.focus }] : []

            return result(at(state, action.row, action.col, dims, action.extend === true), effects)
        }

        case 'drag':
            if (state.mode !== 'nav' || !state.anchor) {
                return result(state, [], false)
            }

            return result(at(state, action.row, action.col, dims, true))

        case 'blur':
            if (state.mode !== 'edit' || !state.focus) {
                return result(state, [], false)
            }

            return result({ ...state, mode: 'nav' }, [{ type: 'commit', cell: state.focus, blur: true }])

        case 'key':
            return state.mode === 'edit' ? editKey(state, action, dims) : navKey(state, action, dims)

        default:
            return result(state, [], false)
    }
}

function editKey(state, action, dims) {
    const focus = state.focus

    if (!focus) {
        return result({ ...state, mode: 'nav' }, [], false)
    }

    switch (action.key) {
        case 'Enter': {
            const next = at(state, focus.row + (action.shift ? -1 : 1), focus.col, dims)

            return result(next, [{ type: 'commit', cell: focus }])
        }

        case 'Tab': {
            const target = tabTarget(focus, dims, action.shift === true)

            if (!target) {
                // Leaving the grid: commit, stay on the cell, and let the browser move on.
                return result({ ...state, mode: 'nav' }, [{ type: 'commit', cell: focus }], false)
            }

            return result(at(state, target.row, target.col, dims), [{ type: 'commit', cell: focus }])
        }

        case 'Escape':
            return result({ ...state, mode: 'nav' }, [{ type: 'cancel', cell: focus }])

        default:
            // Arrows, Home/End, letters: the editor owns them.
            return result(state, [], false)
    }
}

function navKey(state, action, dims) {
    const { key } = action
    const focus = state.focus
    const ctrl = action.ctrl === true
    const shift = action.shift === true

    if (!focus) {
        // Nothing selected yet: any navigation key lands on the first cell.
        if (key in ARROWS || key === 'Tab' || key === 'Enter') {
            return result(at(state, 0, 0, dims))
        }

        return result(state, [], false)
    }

    if (key in ARROWS) {
        const [dRow, dCol] = ARROWS[key]

        // Ctrl+Arrow jumps to the edge of the grid in that direction.
        const row = ctrl && dRow !== 0 ? (dRow < 0 ? 0 : dims.rows - 1) : focus.row + dRow
        const col = ctrl && dCol !== 0 ? (dCol < 0 ? 0 : dims.cols - 1) : focus.col + dCol

        return result(at(state, row, col, dims, shift))
    }

    switch (key) {
        case 'Tab': {
            const target = tabTarget(focus, dims, shift)

            return target ? result(at(state, target.row, target.col, dims)) : result(state, [], false)
        }

        case 'Enter':
            if (ctrl || action.alt) {
                return result(state, [], false)
            }

            return result({ ...state, mode: 'edit' }, [{ type: 'startEdit' }])

        case 'F2':
            return result({ ...state, mode: 'edit' }, [{ type: 'startEdit' }])

        case 'Escape':
            return result({ ...state, anchor: focus })

        case 'Delete':
        case 'Backspace':
            return result(state, [{ type: 'clear' }])

        case 'Home':
            return result(at(state, ctrl ? 0 : focus.row, 0, dims, shift))

        case 'End':
            return result(at(state, ctrl ? dims.rows - 1 : focus.row, dims.cols - 1, dims, shift))
    }

    if (ctrl && !action.alt) {
        switch (key.toLowerCase()) {
            case 'a':
                return result(
                    { mode: 'nav', anchor: { row: 0, col: 0 }, focus: { row: dims.rows - 1, col: dims.cols - 1 } },
                    [{ type: 'selectAll' }],
                )
            case 'd':
                return result(state, [{ type: 'fillDown' }])
            case 'r':
                return result(state, [{ type: 'fillRight' }])
        }

        // Ctrl+C / X / V are handled through the clipboard events, not here.
        return result(state, [], false)
    }

    // A printable character starts editing and REPLACES the content, like Excel.
    if (key.length === 1 && !ctrl && !action.alt) {
        return result({ ...state, mode: 'edit' }, [{ type: 'startEdit', replace: true, char: key }])
    }

    return result(state, [], false)
}
