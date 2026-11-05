<script setup>
import { computed, ref } from 'vue'
import { useElementSize } from '@vueuse/core'
import { compact, dayFull, dayLabel, exact, niceMax } from '@/lib/format'

/**
 * Requests per day, stacked by how they turned out.
 *
 * Built by hand rather than with a charting library: the only thing needed is
 * one column chart, and hand-drawn SVG keeps the marks on the project's own
 * tokens so the chart is theme-correct in both modes with nothing to reconcile.
 *
 * Mark specs are deliberate, not decorative. Columns are capped at 24px so the
 * band keeps its air; the top of each stack is rounded 4px and square at the
 * baseline, so the eye reads one bar growing from one line; and a 2px gap in
 * the surface colour separates touching segments, which is what makes adjacent
 * steps legible without drawing a border around them.
 */
const props = defineProps({
    /** @type {Array<{day: string, success: number, client_errors: number, server_errors: number, requests: number}>} */
    series: { type: Array, required: true },
    height: { type: Number, default: 220 },
})

const plot = ref(null)
const { width } = useElementSize(plot)

const hovered = ref(null)

// Errors sit on top of the success mass: a handful of 5xx among a hundred
// thousand 2xx is a few pixels tall, and at the top of the stack those pixels
// are against open space instead of being lost inside the block.
const stack = [
    { key: 'success', label: 'Success', token: '--chart-success' },
    { key: 'client_errors', label: 'Client errors', token: '--chart-client-error' },
    { key: 'server_errors', label: 'Server errors', token: '--chart-server-error' },
]

const GAP = 2
const RADIUS = 4
const MAX_COLUMN = 24
const AXIS_WIDTH = 44
const LABEL_HEIGHT = 22

const plotWidth = computed(() => Math.max(0, width.value - AXIS_WIDTH))
const plotHeight = computed(() => props.height - LABEL_HEIGHT)

const max = computed(() => niceMax(Math.max(...props.series.map((day) => day.requests), 0)))

const ticks = computed(() => {
    const count = 4

    return Array.from({ length: count + 1 }, (_, index) => {
        const value = (max.value / count) * index

        return { value, y: plotHeight.value - (value / max.value) * plotHeight.value }
    })
})

const band = computed(() => (props.series.length ? plotWidth.value / props.series.length : 0))
const columnWidth = computed(() => Math.min(MAX_COLUMN, Math.max(2, band.value * 0.6)))

/**
 * Lay out every segment once, so the template only draws.
 */
const columns = computed(() => {
    if (!plotWidth.value || !props.series.length) {
        return []
    }

    return props.series.map((day, index) => {
        const x = AXIS_WIDTH + band.value * index + (band.value - columnWidth.value) / 2

        const present = stack.filter((series) => (day[series.key] ?? 0) > 0)
        const segments = []

        let offset = 0

        present.forEach((series, position) => {
            const value = day[series.key]
            const full = (value / max.value) * plotHeight.value
            const isTop = position === present.length - 1

            // The gap is taken off the top of every segment but the last, so
            // the stack still totals the right height overall.
            const height = Math.max(1, full - (isTop ? 0 : GAP))
            const y = plotHeight.value - offset - full

            segments.push({
                ...series,
                value,
                x,
                y,
                height,
                // Only the crown of the stack is rounded; everything below it
                // meets its neighbour square.
                rounded: isTop,
            })

            offset += full
        })

        return {
            day: day.day,
            requests: day.requests,
            x,
            segments,
            // The hit target spans the whole band and the full plot height, so
            // a one-pixel bar is still easy to hover.
            hit: { x: AXIS_WIDTH + band.value * index, width: band.value },
        }
    })
})

/**
 * Show every label when they fit, otherwise thin them out — a rotated or
 * overlapping axis is worse than a sparser one.
 */
const labelEvery = computed(() => {
    if (!band.value) {
        return 1
    }

    return Math.max(1, Math.ceil(54 / band.value))
})

const tooltip = computed(() => {
    if (hovered.value === null) {
        return null
    }

    const day = props.series[hovered.value]

    if (!day) {
        return null
    }

    const column = columns.value[hovered.value]

    return {
        day,
        // Flip to the left of the cursor near the right edge so the panel never
        // leaves the card.
        left: column.hit.x + column.hit.width / 2,
        flip: column.hit.x + column.hit.width / 2 > width.value * 0.65,
    }
})

const summary = computed(() => {
    const total = props.series.reduce((sum, day) => sum + day.requests, 0)
    const errors = props.series.reduce((sum, day) => sum + day.client_errors + day.server_errors, 0)

    return `Requests per day over ${props.series.length} days: ${exact(total)} requests, ${exact(errors)} of them errors.`
})
</script>

<template>
    <figure class="m-0">
        <div ref="plot" class="relative">
            <svg
                :height="height"
                class="block w-full"
                role="img"
                :aria-label="summary"
                @mouseleave="hovered = null"
            >
                <!-- Gridlines first, so data always sits on top of them. -->
                <g aria-hidden="true">
                    <line
                        v-for="tick in ticks"
                        :key="`grid-${tick.value}`"
                        :x1="AXIS_WIDTH"
                        :x2="width"
                        :y1="tick.y"
                        :y2="tick.y"
                        stroke="var(--chart-grid)"
                        stroke-width="1"
                    />
                    <text
                        v-for="tick in ticks"
                        :key="`tick-${tick.value}`"
                        :x="AXIS_WIDTH - 8"
                        :y="tick.y + 4"
                        text-anchor="end"
                        class="fill-muted-foreground text-[11px] tabular-nums"
                    >
                        {{ compact(tick.value) }}
                    </text>
                </g>

                <g v-for="(column, index) in columns" :key="column.day">
                    <!-- Hovered band gets a wash, so the pointer has somewhere
                         to land and the reading is unambiguous. -->
                    <rect
                        v-if="hovered === index"
                        :x="column.hit.x"
                        :width="column.hit.width"
                        y="0"
                        :height="plotHeight"
                        fill="var(--muted)"
                        aria-hidden="true"
                    />

                    <template v-for="segment in column.segments" :key="`${column.day}-${segment.key}`">
                        <rect
                            :x="segment.x"
                            :y="segment.y"
                            :width="columnWidth"
                            :height="segment.height"
                            :rx="segment.rounded ? RADIUS : 0"
                            :fill="`var(${segment.token})`"
                        />
                        <!-- A rounded top would otherwise round the segment's
                             bottom too; this squares it back onto its
                             neighbour. -->
                        <rect
                            v-if="segment.rounded && segment.height > RADIUS"
                            :x="segment.x"
                            :y="segment.y + RADIUS"
                            :width="columnWidth"
                            :height="segment.height - RADIUS"
                            :fill="`var(${segment.token})`"
                        />
                    </template>

                    <text
                        v-if="index % labelEvery === 0"
                        :x="column.hit.x + column.hit.width / 2"
                        :y="height - 6"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[11px]"
                    >
                        {{ dayLabel(column.day) }}
                    </text>

                    <rect
                        :x="column.hit.x"
                        :width="column.hit.width"
                        y="0"
                        :height="plotHeight"
                        fill="transparent"
                        @mouseenter="hovered = index"
                    />
                </g>

                <!-- The baseline is the one axis line worth drawing. -->
                <line
                    :x1="AXIS_WIDTH"
                    :x2="width"
                    :y1="plotHeight"
                    :y2="plotHeight"
                    stroke="var(--chart-grid)"
                    stroke-width="1"
                    aria-hidden="true"
                />
            </svg>

            <div
                v-if="tooltip"
                class="pointer-events-none absolute top-2 z-10 w-48 rounded-lg border bg-popover p-3 text-popover-foreground shadow-md"
                :style="{
                    left: `${tooltip.left}px`,
                    transform: tooltip.flip ? 'translateX(-100%) translateX(-12px)' : 'translateX(12px)',
                }"
            >
                <p class="text-xs font-medium">{{ dayFull(tooltip.day.day) }}</p>
                <dl class="mt-2 space-y-1">
                    <div v-for="series in stack" :key="series.key" class="flex items-center gap-2 text-xs">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: `var(${series.token})` }"
                            aria-hidden="true"
                        />
                        <dt class="text-muted-foreground">{{ series.label }}</dt>
                        <dd class="ml-auto tabular-nums">{{ exact(tooltip.day[series.key]) }}</dd>
                    </div>
                    <div class="flex items-center gap-2 border-t pt-1 text-xs">
                        <dt class="text-muted-foreground">Total</dt>
                        <dd class="ml-auto font-medium tabular-nums">{{ exact(tooltip.day.requests) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- The legend is not optional: with three series, colour alone must
             never be the only way to tell them apart. -->
        <figcaption class="flex flex-wrap items-center gap-x-4 gap-y-1 pt-2">
            <span v-for="series in stack" :key="series.key" class="inline-flex items-center gap-1.5 text-xs">
                <span
                    class="size-2.5 rounded-sm"
                    :style="{ backgroundColor: `var(${series.token})` }"
                    aria-hidden="true"
                />
                <span class="text-muted-foreground">{{ series.label }}</span>
            </span>
        </figcaption>
    </figure>
</template>
