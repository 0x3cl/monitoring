<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus, Search } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataPagination from '@/components/DataPagination.vue'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import { useApplicationsStore } from '@/stores/applications'
import { useMetaStore } from '@/stores/meta'

const applications = useApplicationsStore()
const meta = useMetaStore()

const healthRoles = { HEALTHY: 'good', WARNING: 'warning', CRITICAL: 'critical', UNKNOWN: 'neutral' }

onMounted(() => {
    meta.load()
    applications.fetch()
})

// The Select components emit '' for "All", which the store already drops.
function filter(key, value) {
    applications.filters[key] = value === 'all' ? '' : value
    applications.fetch()
}
</script>

<template>
    <div>
        <PageHeader title="Applications" description="Registered systems and their reporting state.">
            <template #actions>
                <Button as-child size="sm">
                    <RouterLink :to="{ name: 'applications.register' }">
                        <Plus />
                        Register
                    </RouterLink>
                </Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2 pb-4">
            <div class="relative">
                <Search class="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" aria-hidden="true" />
                <Input
                    v-model="applications.filters.search"
                    class="w-56 pl-8"
                    placeholder="Name or APP code"
                    aria-label="Search applications"
                    @keyup.enter="applications.fetch()"
                />
            </div>

            <Select
                :model-value="applications.filters.environment || 'all'"
                @update:model-value="filter('environment', $event)"
            >
                <SelectTrigger class="w-40"><SelectValue placeholder="Environment" /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All environments</SelectItem>
                    <SelectItem v-for="option in meta.environments" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select
                :model-value="applications.filters.status || 'all'"
                @update:model-value="filter('status', $event)"
            >
                <SelectTrigger class="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem v-for="option in meta.applicationStatuses" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="overflow-hidden rounded-lg border bg-card">
            <DataState
                :loading="applications.loading"
                :error="applications.error"
                :empty="!applications.loading && !applications.error && applications.items.length === 0"
                empty-message="No applications match these filters."
                @retry="applications.fetch()"
            />

            <Table v-if="!applications.loading && applications.items.length">
                <TableHeader>
                    <TableRow>
                        <TableHead>Application</TableHead>
                        <TableHead>Environment</TableHead>
                        <TableHead>Integration</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Health</TableHead>
                        <TableHead class="text-right">Events</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="application in applications.items" :key="application.app_code">
                        <TableCell>
                            <Button as-child variant="link" class="h-auto p-0">
                                <RouterLink
                                    :to="{ name: 'applications.show', params: { appCode: application.app_code } }"
                                >
                                    {{ application.name }}
                                </RouterLink>
                            </Button>
                            <span class="ml-2 font-mono text-xs text-muted-foreground">
                                {{ application.app_code }}
                            </span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ application.environment }}</TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ application.integration_type?.name ?? '—' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ application.status_label }}</TableCell>
                        <TableCell>
                            <StatusIndicator :role="healthRoles[application.health]" :label="application.health" />
                        </TableCell>
                        <TableCell class="text-right tabular-nums text-muted-foreground">
                            {{ (application.events_count ?? 0).toLocaleString() }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <DataPagination :meta="applications.meta" @change="applications.fetch($event)" />
        </div>
    </div>
</template>
