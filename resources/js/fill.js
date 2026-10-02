/**
 * Range arithmetic, fill-down/right and the paste plan. Pure, no DOM.
 * A cell is {row, col}; a rect is {top, left, bottom, right} (inclusive, normalised).
 */

/**
 * @param {{row: number, col: number}} a
 * @param {{row: number, col: number}} b
 */
export function rectOf(a, b) {
    return {
        top: Math.min(a.row, b.row),
        left: Math.min(a.col, b.col),
        bottom: Math.max(a.row, b.row),
        right: Math.max(a.col, b.col),
    }
}

export function inRect(rect, row, col) {
    return rect !== null && row >= rect.top && row <= rect.bottom && col >= rect.left && col <= rect.right
}

export function rectSize(rect) {
    return { rows: rect.bottom - rect.top + 1, cols: rect.right - rect.left + 1 }
}

/**
 * Ctrl+D. Every row below the first one in the selection gets the first row's value, per
 * column. A single-row selection copies from the row ABOVE it (Excel behaviour); on the
 * first row there is nothing to copy.
 *
 * @param {{top: number, left: number, bottom: number, right: number}} rect
 * @param {(row: number, col: number) => string} read
 * @returns {Array<{row: number, col: number, value: string}>}
 */
export function fillDown(rect, read) {
    const out = []

    if (rect.top === rect.bottom) {
        if (rect.top === 0) {
            return out
        }

        for (let col = rect.left; col <= rect.right; col++) {
            out.push({ row: rect.top, col, value: read(rect.top - 1, col) })
        }

        return out
    }

    for (let col = rect.left; col <= rect.right; col++) {
        const value = read(rect.top, col)

        for (let row = rect.top + 1; row <= rect.bottom; row++) {
            out.push({ row, col, value })
        }
    }

    return out
}

/** Ctrl+R: the same, to the right. */
export function fillRight(rect, read) {
    const out = []

    if (rect.left === rect.right) {
        if (rect.left === 0) {
            return out
        }

        for (let row = rect.top; row <= rect.bottom; row++) {
            out.push({ row, col: rect.left, value: read(row, rect.left - 1) })
        }

        return out
    }

    for (let row = rect.top; row <= rect.bottom; row++) {
        const value = read(row, rect.left)

        for (let col = rect.left + 1; col <= rect.right; col++) {
            out.push({ row, col, value })
        }
    }

    return out
}

/**
 * Where do the pasted cells go?
 *  - a 1x1 clipboard over a bigger selection fills the whole selection (Excel/Sheets);
 *  - anything else is anchored at the selection's top-left cell and clipped to the grid
 *    (never grows it: there are no rows to create).
 *
 * @param {string[][]} matrix       parsed clipboard
 * @param {{top: number, left: number, bottom: number, right: number}} selection
 * @param {{rows: number, cols: number}} dims
 * @returns {Array<{row: number, col: number, value: string}>}
 */
export function pastePlan(matrix, selection, dims) {
    const out = []

    if (matrix.length === 0) {
        return out
    }

    const single = matrix.length === 1 && matrix[0].length === 1
    const size = rectSize(selection)

    if (single && (size.rows > 1 || size.cols > 1)) {
        for (let row = selection.top; row <= selection.bottom; row++) {
            for (let col = selection.left; col <= selection.right; col++) {
                out.push({ row, col, value: matrix[0][0] })
            }
        }

        return out
    }

    matrix.forEach((line, i) => {
        line.forEach((value, j) => {
            const row = selection.top + i
            const col = selection.left + j

            if (row < dims.rows && col < dims.cols) {
                out.push({ row, col, value })
            }
        })
    })

    return out
}
