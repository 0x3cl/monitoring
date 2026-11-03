<script setup>
import { computed } from 'vue'

/**
 * One headline number. Deliberately not a one-bar chart.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    hint: { type: String, default: null },
    role: { type: String, default: null },
})

// Nothing wrong is happening at zero, so a count of none stays in plain ink
// rather than wearing an alarm colour.
const tone = computed(() => (props.role && props.value !== 0 ? `var(--status-${props.role})` : null))
</script>

<template>
    <div class="rounded-lg border bg-card p-4">
        <p class="text-xs font-medium tracking-wider text-muted-foreground uppercase">{{ label }}</p>
        <p
            class="mt-1.5 text-2xl font-semibold tabular-nums"
            :style="tone ? { color: tone } : null"
        >
            {{ typeof value === 'number' ? value.toLocaleString() : value }}
        </p>
        <p v-if="hint" class="mt-0.5 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
