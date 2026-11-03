<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Activity } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')

async function submit() {
    if (await auth.login(email.value, password.value)) {
        router.push(route.query.redirect ?? { name: 'overview' })
    }
}
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 px-4">
        <div class="absolute top-4 right-4">
            <ThemeToggle />
        </div>

        <div class="flex items-center gap-2">
            <span class="grid size-7 place-items-center rounded bg-primary text-primary-foreground">
                <Activity class="size-4" />
            </span>
            <span class="text-sm font-semibold tracking-[0.22em] uppercase">Sentinel</span>
        </div>

        <Card class="w-full max-w-sm">
            <CardHeader>
                <CardTitle>Sign in</CardTitle>
                <CardDescription>Monitoring platform administration</CardDescription>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="email">Email or username</Label>
                        <Input id="email" v-model="email" type="text" autocomplete="username" required />
                        <p
                            v-if="auth.error?.errors?.email"
                            class="text-xs"
                            :style="{ color: 'var(--status-critical)' }"
                        >
                            {{ auth.error.errors.email[0] }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="password"
                            type="password"
                            autocomplete="current-password"
                            required
                        />
                    </div>

                    <p
                        v-if="auth.error && !auth.error.errors?.email"
                        class="text-xs"
                        :style="{ color: 'var(--status-critical)' }"
                        role="alert"
                    >
                        {{ auth.error.message }}
                    </p>

                    <Button type="submit" class="w-full" :disabled="auth.loading">
                        {{ auth.loading ? 'Signing in…' : 'Sign in' }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <p class="max-w-sm text-center text-xs text-muted-foreground">
            Create additional administrators with
            <code class="font-mono">php artisan sentinel:create-admin</code>.
        </p>
    </div>
</template>
