/**
 * Number and date formatting for the dashboard.
 *
 * Traffic numbers get large quickly, and a column of raw integers like
 * 1284913 is read as "a lot" and nothing more. Compacting them is what keeps a
 * screen of statistics legible.
 */

/**
 * 948 · 12.3K · 4.1M — three significant figures at most.
 */
export function compact(value) {
    if (value === null || value === undefined) {
        return '—'
    }

    const number = Number(value)

    if (!Number.isFinite(number)) {
        return '—'
    }

    if (Math.abs(number) < 1000) {
        return String(Math.round(number))
    }

    return new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(number)
}

/**
 * The exact figure, for tooltips and table cells where precision is the point.
 */
export function exact(value) {
    if (value === null || value === undefined) {
        return '—'
    }

    return Number(value).toLocaleString()
}

export function percent(value, digits = 1) {
    if (value === null || value === undefined) {
        return '—'
    }

    return `${Number(value).toFixed(digits)}%`
}

export function milliseconds(value) {
    if (value === null || value === undefined) {
        return '—'
    }

    const number = Number(value)

    return number >= 1000 ? `${(number / 1000).toFixed(1)}s` : `${Math.round(number)}ms`
}

/**
 * 'Mon 4' — short enough to sit under a bar without rotating.
 */
export function dayLabel(iso) {
    const date = new Date(`${iso}T00:00:00`)

    return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' })
}

/**
 * '4 Nov 2026' — for tooltips and table rows, where there is room to be plain.
 */
export function dayFull(iso) {
    const date = new Date(`${iso}T00:00:00`)

    return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

/**
 * A signed change against a named period, or null when there is nothing to
 * compare against — growth from zero is not a percentage.
 */
export function signedPercent(value) {
    if (value === null || value === undefined) {
        return null
    }

    const number = Number(value)

    return `${number > 0 ? '+' : ''}${number.toFixed(1)}%`
}

/**
 * Round a maximum up to a clean axis bound, so ticks land on readable numbers.
 */
export function niceMax(value) {
    if (!value || value <= 0) {
        return 1
    }

    const magnitude = 10 ** Math.floor(Math.log10(value))
    const scaled = value / magnitude

    const step = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 2.5 ? 2.5 : scaled <= 5 ? 5 : 10

    return step * magnitude
}
