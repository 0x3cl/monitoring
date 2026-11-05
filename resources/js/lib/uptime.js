/**
 * How heartbeat statuses map onto the indicator roles.
 *
 * Maintenance is 'serious' rather than 'critical': the application is
 * unavailable, but deliberately, and an operator watching a deploy should not
 * be looking at the same colour as a real outage.
 */
export const uptimeRoles = {
    up: 'good',
    degraded: 'warning',
    maintenance: 'serious',
    down: 'critical',
    unreachable: 'critical',
    unknown: 'neutral',
}

export const reachabilityRoles = {
    reachable: 'good',
    unreachable: 'critical',
    unknown: 'neutral',
}

const uptimeLabels = {
    up: 'Up',
    degraded: 'Degraded',
    maintenance: 'Maintenance',
    down: 'Down',
    unreachable: 'Unreachable',
    unknown: 'Unknown',
}

export function uptimeRole(status) {
    return uptimeRoles[status] ?? 'neutral'
}

export function uptimeLabel(status) {
    return uptimeLabels[status] ?? 'Unknown'
}

/**
 * A duration in words, for 'down since' and 'up for'.
 */
export function since(timestamp) {
    if (!timestamp) {
        return null
    }

    const seconds = Math.max(0, Math.round((Date.now() - new Date(timestamp).getTime()) / 1000))

    if (seconds < 60) {
        return `${seconds}s`
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`
    }

    return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`
}
