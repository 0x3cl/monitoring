<script setup>
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'

/**
 * Severity as a badge. DEBUG and INFO stay neutral: the status palette is
 * reserved for states that actually need attention.
 */
const props = defineProps({
    severity: { type: String, required: true },
})

const role = computed(
    () =>
        ({
            WARNING: 'warning',
            ERROR: 'serious',
            CRITICAL: 'critical',
        })[props.severity] ?? null,
)
</script>

<template>
    <Badge variant="outline" class="font-mono text-[11px] tracking-wide">
        <span
            v-if="role"
            class="size-1.5 rounded-full"
            :style="{ backgroundColor: `var(--status-${role})` }"
            aria-hidden="true"
        />
        {{ severity }}
    </Badge>
</template>
