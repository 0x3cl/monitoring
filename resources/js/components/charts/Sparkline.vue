<script setup>
import { computed, ref } from 'vue'
import { useElementSize } from '@vueuse/core'

/**
 * A small trend line, for a stat tile.
 *
 * No axes, no gridlines, no labels: it answers "which way is this going" and
 * nothing else. The current value gets an end marker with a ring in the surface
 * colour, so it stays legible wherever the line happens to finish.
 */
const props = defineProps({
    values: { type: Array, required: true },
    height: { type: Number, default: 36 },
    token: { type: String, default: '--chart-success' },
})

const wrapper = ref(null)
const { width } = useElementSize(wrapper)

const RADIUS = 4

const points = computed(() => {
    if (!width.value || props.values.length < 2) {
        return []
    }

    const max = Math.max(...props.values, 1)
    // Inset by the marker radius so the end dot and its ring are never clipped
    // by the viewport edge.
    const usableWidth = width.value - RADIUS * 2 - 2
    const usableHeight = props.height - RADIUS * 2

    return props.values.map((value, index) => ({
        x: RADIUS + 1 + (index / (props.values.length - 1)) * usableWidth,
        y: RADIUS + usableHeight - (value / max) * usableHeight,
    }))
})

const path = computed(() => points.value.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${point.y}`).join(' '))

const last = computed(() => points.value.at(-1) ?? null)
</script>

<template>
    <div ref="wrapper" class="w-full">
        <svg v-if="points.length" :height="height" class="block w-full" aria-hidden="true">
            <path
                :d="path"
                fill="none"
                :stroke="`var(${token})`"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle
                v-if="last"
                :cx="last.x"
                :cy="last.y"
                :r="RADIUS"
                :fill="`var(${token})`"
                stroke="var(--card)"
                stroke-width="2"
            />
        </svg>
    </div>
</template>
