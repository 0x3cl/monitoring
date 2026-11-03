<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Search } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataPagination from '@/components/DataPagination.vue'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import SeverityBadge from '@/components/SeverityBadge.vue'
import { useEventsStore } from '@/stores/events'
import { useMetaStore } from '@/stores/meta'

const events = useEventsStore()
const meta = useMetaStore()
const route = useRoute()

const selected = ref(null)
const live = ref(true)

// A 10 second poll keeps the table current without the infrastructure a
// websocket connection would require.
let timer = null

onMounted(() => {
    meta.load()

    if (route.query.application) {
        events.filters.application = String(route.query.application)
    }

    events.fetch()

    timer = setInterval(() => {
        if (live.value) {
            events.fetch(events.meta?.current_page ?? 1, { quiet: true })
        }
    }, 10000)
})

onUnmounted(() => clearInterval(timer))

function filter(key, value) {
    events.filters[key] = value === 'all' ? '' : value
    events.fetch()
}

function statusRole(status) {
    if (status === null || status === undefined) {
        return null
    }

    if (status >= 500) {
        return 'critical'
    }

    return status >= 400 ? 'warning' : null
}
</script>

<template>
    <div>
        <PageHeader title="Events" description="Everything SENTINEL has received, newest first.">
            <template #actions>
                <Label class="flex items-center gap-2 text-sm font-normal">
                    <input v-model="live" type="checkbox" class="size-3.5 accent-primary" />
                    Live
                </Label>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2 pb-4">
            <div class="relative">
                <Search class="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" aria-hidden="true" />
                <Input
                    v-model="events.filters.application"
                    class="w-40 pl-8 font-mono"
                    placeholder="APP-00001"
                    aria-label="Filter by application"
                    @keyup.enter="events.fetch()"
                />
            </div>

            <Select :model-value="events.filters.severity || 'all'" @update:model-value="filter('severity', $event)">
                <SelectTrigger class="w-36"><SelectValue placeholder="Severity" /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All severities</SelectItem>
                    <SelectItem v-for="option in meta.severities" :key="option.value" :value="option.value">
                        {{ option.value }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select
                :model-value="events.filters.event_type || 'all'"
                @update:model-value="filter('event_type', $event)"
            >
                <SelectTrigger class="w-48"><SelectValue placeholder="Event type" /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All event types</SelectItem>
                    <SelectItem v-for="option in meta.eventTypes" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Input
                v-model="events.filters.endpoint"
                class="w-44 font-mono"
                placeholder="/api/orders"
                aria-label="Filter by endpoint"
                @keyup.enter="events.fetch()"
            />
        </div>

        <div class="overflow-hidden rounded-lg border bg-card">
            <DataState
                :loading="events.loading"
                :error="events.error"
                :empty="!events.loading && !events.error && events.items.length === 0"
                empty-message="No events match these filters."
                :rows="8"
                @retry="events.fetch()"
            />

            <Table v-if="!events.loading && events.items.length">
                <TableHeader>
                    <TableRow>
                        <TableHead>Occurred</TableHead>
                        <TableHead>Application</TableHead>
                        <TableHead>Severity</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Endpoint</TableHead>
                        <TableHead class="text-right">Status</TableHead>
                        <TableHead class="text-right">Time</TableHead>
                        <TableHead>Message</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="event in events.items"
                        :key="event.id"
                        class="cursor-pointer"
                        @click="selected = event"
                    >
                        <TableCell class="tabular-nums whitespace-nowrap">
                            {{ new Date(event.occurred_at).toLocaleString() }}
                        </TableCell>
                        <TableCell class="font-mono text-xs text-muted-foreground">
                            {{ event.application?.app_code ?? '—' }}
                        </TableCell>
                        <TableCell><SeverityBadge :severity="event.severity" /></TableCell>
                        <TableCell class="text-muted-foreground">{{ event.event_type_label }}</TableCell>
                        <TableCell class="font-mono text-xs">
                            <span v-if="event.http_method" class="mr-1 text-muted-foreground">
                                {{ event.http_method }}
                            </span>
                            {{ event.endpoint ?? '—' }}
                        </TableCell>
                        <TableCell
                            class="text-right tabular-nums"
                            :style="
                                statusRole(event.http_status)
                                    ? { color: `var(--status-${statusRole(event.http_status)})` }
                                    : null
                            "
                        >
                            {{ event.http_status ?? '—' }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums text-muted-foreground">
                            {{ event.response_time_ms !== null ? `${event.response_time_ms} ms` : '—' }}
                        </TableCell>
                        <TableCell class="max-w-xs truncate text-muted-foreground">
                            {{ event.message ?? '—' }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <DataPagination :meta="events.meta" @change="events.fetch($event)" />
        </div>

        <Dialog :open="selected !== null" @update:open="selected = null">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <SeverityBadge v-if="selected" :severity="selected.severity" />
                        {{ selected?.event_type_label }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ selected?.application?.app_code }} ·
                        {{ selected ? new Date(selected.occurred_at).toLocaleString() : '' }}
                        <template v-if="selected?.fingerprint">
                            · signature {{ selected.fingerprint.slice(0, 12) }}
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <pre
                    class="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed"
                >{{ JSON.stringify(selected?.data, null, 2) }}</pre>
            </DialogContent>
        </Dialog>
    </div>
</template>
