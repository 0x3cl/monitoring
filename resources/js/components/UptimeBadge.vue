<script setup>
import { computed } from 'vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import { uptimeLabel, uptimeRole } from '@/lib/uptime'

/**
 * What an outbound heartbeat probe last saw.
 *
 * Deliberately distinct from the health indicator: an application can be
 * pushing events happily while serving nothing but errors, and the two
 * questions deserve two answers.
 */
const props = defineProps({
    status: { type: String, default: null },
    label: { type: String, default: null },
})

const role = computed(() => uptimeRole(props.status))
const text = computed(() => props.label ?? uptimeLabel(props.status))
</script>

<template>
    <StatusIndicator :role="role" :label="text" />
</template>
