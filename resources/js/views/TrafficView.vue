<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import PageHeader from '@/components/PageHeader.vue'
import TrafficPanel from '@/components/TrafficPanel.vue'
import { compact, percent } from '@/lib/format'
import { useTrafficStore } from '@/stores/traffic'

const traffic = useTrafficStore()

// Rollups are rebuilt every few minutes, so refreshing faster than that would
// only re-fetch the same rows.
let timer = null

onMounted(() => {
    traffic.appCode = null
    traffic.fetch()
    timer = setInterval(() => traffic.fetch({ quiet: true }), 60000)
})

onUnmounted(() => clearInterval(timer))

const applications = computed(() => traffic.report?.applications ?? [])
</script>

<template>
    <div>
        <PageHeader
            title="Traffic"
            description="Request volume, errors and callers across every monitored application."
        />

        <TrafficPanel
            :report="traffic.report"
            :loading="traffic.loading"
            :error="traffic.error"
            @change-range="traffic.setRange"
            @retry="traffic.fetch()"
        >
            <!-- Which application the traffic belongs to is the one breakdown
                 the combined page adds over a single application's own tab. -->
            <template #footer>
                <section
                    v-if="applications.length"
                    aria-label="Traffic by application"
                    class="overflow-hidden rounded-lg border bg-card"
                >
                    <h3 class="border-b p-4 text-sm font-medium">By application</h3>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Application</TableHead>
                                <TableHead class="text-right">Requests</TableHead>
                                <TableHead class="text-right">Error rate</TableHead>
                                <TableHead class="text-right">Busiest day</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="row in applications" :key="row.app_code">
                                <TableCell>
                                    <Button as-child variant="link" class="h-auto p-0">
                                        <RouterLink
                                            :to="{
                                                name: 'applications.show',
                                                params: { appCode: row.app_code },
                                            }"
                                        >
                                            {{ row.name }}
                                        </RouterLink>
                                    </Button>
                                    <span class="ml-2 font-mono text-xs text-muted-foreground">
                                        {{ row.app_code }}
                                    </span>
                                </TableCell>
                                <TableCell class="text-right tabular-nums">{{ compact(row.requests) }}</TableCell>
                                <TableCell
                                    class="text-right tabular-nums"
                                    :style="
                                        row.error_rate >= 5 ? { color: 'var(--status-critical)' } : null
                                    "
                                >
                                    {{ percent(row.error_rate) }}
                                </TableCell>
                                <TableCell class="text-right tabular-nums text-muted-foreground">
                                    {{ compact(row.peak_visitors) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </section>
            </template>
        </TrafficPanel>
    </div>
</template>
