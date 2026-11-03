<script setup>
import { ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { toast } from 'vue-sonner'

const props = defineProps({
    title: { type: String, default: null },
    content: { type: String, required: true },
})

const copied = ref(false)

async function copy() {
    try {
        await navigator.clipboard.writeText(props.content)
        copied.value = true
        setTimeout(() => (copied.value = false), 2000)
    } catch {
        toast.error('Could not copy to the clipboard.')
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border bg-card">
        <div class="flex items-center justify-between gap-3 border-b px-3 py-2">
            <p class="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {{ title ?? 'Configuration' }}
            </p>
            <Button variant="ghost" size="xs" @click="copy">
                <Check v-if="copied" />
                <Copy v-else />
                {{ copied ? 'Copied' : 'Copy' }}
            </Button>
        </div>
        <pre class="overflow-x-auto p-3 text-xs leading-relaxed"><code>{{ content }}</code></pre>
    </div>
</template>
