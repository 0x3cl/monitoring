<script setup>
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { Toaster } from '@/components/ui/sonner'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

// Resolve the session before the first route renders, so a reload lands back
// where the administrator was rather than on the login screen.
onMounted(() => {
    if (!auth.checked) {
        auth.check()
    }
})
</script>

<template>
    <RouterView v-if="auth.checked" />
    <div v-else class="flex min-h-svh items-center justify-center text-sm text-muted-foreground">
        Loading SENTINEL…
    </div>

    <Toaster position="bottom-right" />
</template>
