<script setup>
import { RotateCw, TriangleAlert } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'

/**
 * The three states every data view needs. Keeping them in one component is
 * what makes loading, empty and error handling consistent across the app.
 */
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    empty: { type: Boolean, default: false },
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
    rows: { type: Number, default: 4 },
})

defineEmits(['retry'])
</script>

<template>
    <div v-if="loading" class="space-y-2 p-4">
        <Skeleton v-for="row in rows" :key="row" class="h-9 w-full" />
    </div>

    <div v-else-if="error" class="flex flex-col items-start gap-3 p-6" role="alert">
        <p class="flex items-center gap-2 text-sm font-medium">
            <TriangleAlert class="size-4" :style="{ color: 'var(--status-critical)' }" aria-hidden="true" />
            Request failed
        </p>
        <p class="text-sm text-muted-foreground">{{ error.message }}</p>
        <Button variant="outline" size="sm" @click="$emit('retry')">
            <RotateCw />
            Try again
        </Button>
    </div>

    <p v-else-if="empty" class="p-8 text-center text-sm text-muted-foreground">
        {{ emptyMessage }}
    </p>
</template>
