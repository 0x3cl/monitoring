<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import UptimeBadge from '@/components/UptimeBadge.vue'
import { since } from '@/lib/uptime'
import { useOverviewStore } from '@/stores/overview'

const overview = useOverviewStore()

// Polling is the simplest reliable way to keep the summary current;
// broadcast updates arrive in a later phase.
let timer = null

onMounted(() => {
    overview.fetch()
    timer = setInterval(() => overview.fetch({ quiet: true }), 15000)
})

onUnmounted(() => clearInterval(timer))

const applications = computed(() => overview.data?.applications)
const events = computed(() => overview.data?.events)
const health = computed(() => overview.data?.health ?? [])
const uptime = computed(() => overview.data?.uptime)
const outages = computed(() => overview.data?.outages ?? [])

const windows = [
    { value: '60', label: '1 hour' },
    { value: '360', label: '6 hours' },
    { value: '1440', label: '24 hours' },
    { value: '10080', label: '7 days' },
]

const severityRoles = { WARNING: 'warning', ERROR: 'serious', CRITICAL: 'critical' }
const healthRoles = { HEALTHY: 'good', WARNING: 'warning', CRITICAL: 'critical', UNKNOWN: 'neutral' }

function setWindow(value) {
    overview.windowMinutes = Number(value)
    overview.fetch()
}
</script>

<template>
    <div>
        <PageHeader title="Overview" description="Every registered application at a glance.">
            <template #actions>
                <Select :model-value="String(overview.windowMinutes)" @update:model-value="setWindow">
                    <SelectTrigger class="w-36">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in windows" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </template>
        </PageHeader>

        <DataState
            :loading="overview.loading && !overview.data"
            :error="overview.error"
            @retry="overview.fetch()"
        />

        <div v-if="overview.data" class="space-y-6">
            <section aria-label="Applications">
                <h2 class="pb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">Applications</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard label="Registered" :value="applications.registered" />
                    <StatCard label="Connected" :value="applications.connected" role="good" />
                    <StatCard label="Disconnected" :value="applications.disconnected" role="critical" />
                    <StatCard label="Pending" :value="applications.never_connected" hint="Never reported" />
                </div>
            </section>

            <section aria-label="Availability">
                <h2 class="pb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">Availability</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard label="Up" :value="uptime.up" role="good" />
                    <StatCard label="Degraded" :value="uptime.degraded" role="warning" />
                    <StatCard label="Maintenance" :value="uptime.maintenance" hint="Planned" />
                    <StatCard label="Down" :value="uptime.down" role="critical" />
                    <StatCard
                        label="Unreachable"
                        :value="uptime.unreachable"
                        role="critical"
                        hint="Server not answering"
                    />
                </div>

                <!-- Shown only when something is actually wrong, so an
                     all-clear dashboard stays quiet. -->
                <div v-if="outages.length" class="mt-3 overflow-hidden rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead>Heartbeat</TableHead>
                                <TableHead>Diagnosis</TableHead>
                                <TableHead>For</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="outage in outages" :key="outage.app_code">
                                <TableCell>
                                    <Button as-child variant="link" class="h-auto p-0">
                                        <RouterLink
                                            :to="{
                                                name: 'applications.show',
                                                params: { appCode: outage.app_code },
                                            }"
                                        >
                                            {{ outage.name }}
                                        </RouterLink>
                                    </Button>
                                    <span class="ml-2 font-mono text-xs text-muted-foreground">
                                        {{ outage.app_code }}
                                    </span>
                                </TableCell>
                                <TableCell><UptimeBadge :status="outage.uptime_status" /></TableCell>
                                <TableCell class="text-muted-foreground">{{ outage.explanation }}</TableCell>
                                <TableCell class="tabular-nums text-muted-foreground">
                                    {{ since(outage.since) ?? '—' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>

            <section aria-label="Monitoring events">
                <h2 class="pb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                    Monitoring events
                </h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard
                        v-for="severity in ['INFO', 'WARNING', 'ERROR', 'CRITICAL']"
                        :key="severity"
                        :label="severity"
                        :value="events[severity]"
                        :role="severityRoles[severity] ?? null"
                    />
                    <StatCard label="Total" :value="events.total" />
                </div>
            </section>

            <section aria-label="Application health">
                <h2 class="pb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">Health</h2>

                <div class="overflow-hidden rounded-lg border bg-card">
                    <DataState :empty="health.length === 0" empty-message="No applications registered yet." />

                    <Table v-if="health.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead>Environment</TableHead>
                                <TableHead>Server</TableHead>
                                <TableHead>Health</TableHead>
                                <TableHead>Heartbeat</TableHead>
                                <TableHead>Last event</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="application in health" :key="application.app_code">
                                <TableCell>
                                    <Button as-child variant="link" class="h-auto p-0">
                                        <RouterLink
                                            :to="{
                                                name: 'applications.show',
                                                params: { appCode: application.app_code },
                                            }"
                                        >
                                            {{ application.name }}
                                        </RouterLink>
                                    </Button>
                                    <span class="ml-2 font-mono text-xs text-muted-foreground">
                                        {{ application.app_code }}
                                    </span>
                                </TableCell>
                                <TableCell class="text-muted-foreground">{{ application.environment }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ application.server ?? '—' }}</TableCell>
                                <TableCell>
                                    <StatusIndicator
                                        :role="healthRoles[application.health]"
                                        :label="application.health"
                                    />
                                </TableCell>
                                <TableCell>
                                    <UptimeBadge :status="application.uptime_status" />
                                    <span
                                        v-if="application.last_probe_time_ms !== null"
                                        class="ml-2 text-xs tabular-nums text-muted-foreground"
                                    >
                                        {{ application.last_probe_time_ms }}ms
                                    </span>
                                </TableCell>
                                <TableCell class="tabular-nums text-muted-foreground">
                                    {{
                                        application.last_event_at
                                            ? new Date(application.last_event_at).toLocaleString()
                                            : 'Never'
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </div>
    </div>
</template>
