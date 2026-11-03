<script setup>
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'

const props = defineProps({
    meta: { type: Object, default: null },
})

const emit = defineEmits(['change'])

const current = computed(() => props.meta?.current_page ?? 1)
const last = computed(() => props.meta?.last_page ?? 1)

function go(page) {
    if (page >= 1 && page <= last.value && page !== current.value) {
        emit('change', page)
    }
}
</script>

<template>
    <div v-if="meta && (meta.total ?? 0) > 0" class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3">
        <p class="text-sm text-muted-foreground">
            <span class="tabular-nums">{{ meta.from }}</span>–<span class="tabular-nums">{{ meta.to }}</span> of
            <span class="tabular-nums">{{ meta.total.toLocaleString() }}</span>
        </p>
        <div class="flex items-center gap-2">
            <Button variant="outline" size="icon-sm" :disabled="current <= 1" aria-label="Previous page" @click="go(current - 1)">
                <ChevronLeft />
            </Button>
            <span class="text-sm tabular-nums text-muted-foreground">{{ current }} / {{ last }}</span>
            <Button variant="outline" size="icon-sm" :disabled="current >= last" aria-label="Next page" @click="go(current + 1)">
                <ChevronRight />
            </Button>
        </div>
    </div>
</template>
