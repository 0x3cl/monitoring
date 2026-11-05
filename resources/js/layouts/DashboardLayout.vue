<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { Activity, ChartColumn, LogOut, Menu, Radio, Server, Shapes } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'

const auth = useAuthStore()
const meta = useMetaStore()
const router = useRouter()
const mobileOpen = ref(false)

// `exact` keeps the index route from matching every child path, which
// would otherwise leave Overview highlighted on every page.
const links = [
    { name: 'overview', label: 'Overview', icon: Activity, exact: true },
    { name: 'applications', label: 'Applications', icon: Shapes },
    { name: 'traffic', label: 'Traffic', icon: ChartColumn },
    { name: 'events', label: 'Events', icon: Radio },
    { name: 'servers', label: 'Servers', icon: Server },
]

onMounted(() => meta.load())

async function signOut() {
    await auth.logout()
    router.push({ name: 'login' })
}
</script>

<template>
    <div class="min-h-svh">
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-2 px-4 sm:px-6">
                <RouterLink :to="{ name: 'overview' }" class="flex items-center gap-2">
                    <span class="grid size-6 place-items-center rounded bg-primary text-primary-foreground">
                        <Activity class="size-3.5" />
                    </span>
                    <span class="text-sm font-semibold tracking-[0.18em] uppercase">Sentinel</span>
                </RouterLink>

                <Separator orientation="vertical" class="mx-2 hidden !h-5 md:block" />

                <nav class="hidden items-center gap-0.5 md:flex" aria-label="Main">
                    <Button v-for="link in links" :key="link.name" as-child variant="ghost" size="sm">
                        <RouterLink
                            :to="{ name: link.name }"
                            :active-class="link.exact ? '' : 'bg-accent text-accent-foreground'"
                            exact-active-class="bg-accent text-accent-foreground"
                        >
                            <component :is="link.icon" />
                            {{ link.label }}
                        </RouterLink>
                    </Button>
                </nav>

                <div class="ml-auto flex items-center gap-1">
                    <span class="mr-1 hidden text-sm text-muted-foreground lg:inline">{{ auth.user?.email }}</span>
                    <ThemeToggle />
                    <Button variant="ghost" size="icon-sm" aria-label="Sign out" @click="signOut">
                        <LogOut />
                    </Button>

                    <Sheet v-model:open="mobileOpen">
                        <SheetTrigger as-child>
                            <Button variant="ghost" size="icon-sm" class="md:hidden" aria-label="Open navigation">
                                <Menu />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-64">
                            <SheetTitle class="px-4 pt-4 text-sm tracking-[0.18em] uppercase">Sentinel</SheetTitle>
                            <nav class="flex flex-col gap-1 p-2" aria-label="Main">
                                <Button
                                    v-for="link in links"
                                    :key="link.name"
                                    as-child
                                    variant="ghost"
                                    class="justify-start"
                                    @click="mobileOpen = false"
                                >
                                    <RouterLink
                                        :to="{ name: link.name }"
                                        :active-class="link.exact ? '' : 'bg-accent'"
                                        exact-active-class="bg-accent"
                                    >
                                        <component :is="link.icon" />
                                        {{ link.label }}
                                    </RouterLink>
                                </Button>
                            </nav>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            <RouterView />
        </main>
    </div>
</template>
