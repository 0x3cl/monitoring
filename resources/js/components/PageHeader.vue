<script setup>
/**
 * Consistent page heading: title, optional description, optional actions.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
})
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3 pb-5">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
            <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
