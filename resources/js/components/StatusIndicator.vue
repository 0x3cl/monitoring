<script setup>
import { computed } from 'vue'
import { AlertTriangle, CheckCircle2, Circle, OctagonAlert, XCircle } from 'lucide-vue-next'

/**
 * A state indicator: coloured icon plus its label.
 *
 * The icon and label are never optional — two of the four status colours fall
 * below 3:1 on a light surface, so state must stay readable without colour.
 */
const props = defineProps({
    role: {
        type: String,
        default: 'neutral',
        validator: (value) => ['good', 'warning', 'serious', 'critical', 'neutral'].includes(value),
    },
    label: { type: String, required: true },
})

const icon = computed(
    () =>
        ({
            good: CheckCircle2,
            warning: AlertTriangle,
            serious: OctagonAlert,
            critical: XCircle,
            neutral: Circle,
        })[props.role],
)
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap">
        <component :is="icon" class="size-3.5" :style="{ color: `var(--status-${role})` }" aria-hidden="true" />
        <span>{{ label }}</span>
    </span>
</template>
