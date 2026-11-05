<script setup>
import { onMounted, reactive, ref } from 'vue'
import { Activity } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import { reachabilityRoles } from '@/lib/uptime'
import http, { toError } from '@/lib/http'

const servers = ref([])
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const formError = ref(null)

const probing = ref(null)

const form = reactive({
    name: '',
    hostname: '',
    ip_address: '',
    provider: '',
    // Where heartbeat probes connect. Left blank, the probe falls back to the
    // hostname and then the IP address.
    monitor_host: '',
    monitor_port: '443',
})

/**
 * Queues a TCP reachability probe. One probe per host answers for every
 * application on it, which is what tells 'the server is gone' apart from 'the
 * application on it died'.
 */
async function probeServer(server) {
    probing.value = server.id

    try {
        await http.post(`servers/${server.id}/probe`)
        toast.success('Server probe queued.')
    } catch (caught) {
        toast.error(toError(caught).message)
    } finally {
        probing.value = null
    }
}

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
            monitor_host: form.monitor_host || null,
            monitor_port: Number(form.monitor_port) || 443,
        })

        Object.keys(form).forEach((key) => (form[key] = ''))
        form.monitor_port = '443'
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
                            <TableHead>Reachability</TableHead>
                            <TableHead class="text-right">Applications</TableHead>
                            <TableHead class="text-right">Check</TableHead>
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
                            <TableCell>
                                <StatusIndicator
                                    :role="reachabilityRoles[server.reachability_status] ?? 'neutral'"
                                    :label="server.reachability_status_label ?? 'Unknown'"
                                />
                                <span v-if="server.monitor_target" class="ml-2 font-mono text-xs text-muted-foreground">
                                    {{ server.monitor_target }}:{{ server.monitor_port }}
                                </span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums text-muted-foreground">
                                {{ server.applications_count ?? 0 }}
                            </TableCell>
                            <TableCell class="text-right">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="probing === server.id"
                                    @click="probeServer(server)"
                                >
                                    <Activity />
                                    <span class="sr-only">Probe {{ server.name }}</span>
                                </Button>
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

                        <div class="space-y-2">
                            <Label for="server-monitor-host">Probe host</Label>
                            <Input
                                id="server-monitor-host"
                                v-model="form.monitor_host"
                                placeholder="Defaults to the hostname"
                            />
                            <p class="text-xs text-muted-foreground">
                                A TCP connection here is what tells a dead server apart from a dead application.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="server-monitor-port">Probe port</Label>
                            <Input id="server-monitor-port" v-model="form.monitor_port" inputmode="numeric" />
                            <p
                                v-if="fieldError('monitor_port')"
                                class="text-xs"
                                :style="{ color: 'var(--status-critical)' }"
                            >
                                {{ fieldError('monitor_port') }}
                            </p>
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
