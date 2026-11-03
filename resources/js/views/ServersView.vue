<script setup>
import { onMounted, reactive, ref } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import http, { toError } from '@/lib/http'

const servers = ref([])
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const formError = ref(null)

const form = reactive({
    name: '',
    hostname: '',
    ip_address: '',
    provider: '',
})

async function load() {
    loading.value = true
    error.value = null

    try {
        const { data } = await http.get('servers')
        servers.value = data.data
    } catch (caught) {
        error.value = toError(caught)
    } finally {
        loading.value = false
    }
}

async function submit() {
    saving.value = true
    formError.value = null

    try {
        await http.post('servers', {
            name: form.name,
            hostname: form.hostname || null,
            ip_address: form.ip_address || null,
            provider: form.provider || null,
        })

        Object.keys(form).forEach((key) => (form[key] = ''))
        await load()
        toast.success('Server added.')
    } catch (caught) {
        formError.value = toError(caught)
    } finally {
        saving.value = false
    }
}

function fieldError(field) {
    return formError.value?.errors?.[field]?.[0]
}

onMounted(load)
</script>

<template>
    <div>
        <PageHeader title="Servers" description="Where monitored applications are hosted." />

        <div class="grid gap-6 lg:grid-cols-[1.8fr_1fr]">
            <div class="overflow-hidden rounded-lg border bg-card">
                <DataState
                    :loading="loading"
                    :error="error"
                    :empty="!loading && !error && servers.length === 0"
                    empty-message="No servers yet. Add the first one alongside."
                    @retry="load"
                />

                <Table v-if="!loading && servers.length">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Server</TableHead>
                            <TableHead>Provider</TableHead>
                            <TableHead>State</TableHead>
                            <TableHead class="text-right">Applications</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="server in servers" :key="server.id">
                            <TableCell>
                                <span class="font-medium">{{ server.name }}</span>
                                <span class="ml-2 font-mono text-xs text-muted-foreground">
                                    {{ server.hostname ?? server.ip_address ?? '' }}
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{{ server.provider ?? '—' }}</TableCell>
                            <TableCell>
                                <StatusIndicator
                                    :role="server.is_active ? 'good' : 'neutral'"
                                    :label="server.is_active ? 'Active' : 'Inactive'"
                                />
                            </TableCell>
                            <TableCell class="text-right tabular-nums text-muted-foreground">
                                {{ server.applications_count ?? 0 }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <Card class="h-fit">
                <CardHeader>
                    <CardTitle class="text-base">Add a server</CardTitle>
                    <CardDescription>Only the name is required.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="submit">
                        <div class="space-y-2">
                            <Label for="server-name">Name</Label>
                            <Input id="server-name" v-model="form.name" required placeholder="PROD-SERVER-01" />
                            <p v-if="fieldError('name')" class="text-xs" :style="{ color: 'var(--status-critical)' }">
                                {{ fieldError('name') }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="server-hostname">Hostname</Label>
                            <Input id="server-hostname" v-model="form.hostname" placeholder="prod-01.example.com" />
                        </div>

                        <div class="space-y-2">
                            <Label for="server-ip">IP address</Label>
                            <Input id="server-ip" v-model="form.ip_address" placeholder="10.0.0.5" />
                            <p
                                v-if="fieldError('ip_address')"
                                class="text-xs"
                                :style="{ color: 'var(--status-critical)' }"
                            >
                                {{ fieldError('ip_address') }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="server-provider">Provider</Label>
                            <Input id="server-provider" v-model="form.provider" placeholder="Hetzner" />
                        </div>

                        <Button type="submit" class="w-full" :disabled="saving">
                            {{ saving ? 'Saving…' : 'Add server' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
