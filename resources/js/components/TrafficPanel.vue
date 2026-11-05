<script setup>
import { computed, ref } from 'vue'
import { BarChart3, Table2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import DataState from '@/components/DataState.vue'
import StatCard from '@/components/StatCard.vue'
import Sparkline from '@/components/charts/Sparkline.vue'
import StackedColumnChart from '@/components/charts/StackedColumnChart.vue'
import { compact, dayFull, exact, milliseconds, percent, signedPercent } from '@/lib/format'

/**
 * Everything the traffic view shows, so the combined page and an application's
 * own tab stay identical rather than drifting apart.
 *
 * The restraint is the design: four headline numbers, one chart and two short
 * lists. A traffic page can easily become forty figures nobody reads, so
 * anything beyond "how much, how healthy, how fast, and who" is left out.
 */
const props = defineProps({
    report: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
})

const emit = defineEmits(['change-range', 'retry'])

// The chart is the default reading, but every value in it is also available as
// text: a chart alone would gate the numbers behind colour perception.
const view = ref('chart')

const series = computed(() => props.report?.series ?? [])
const totals = computed(() => props.report?.totals ?? null)
const visitorTrend = computed(() => series.value.map((day) => day.unique_visitors))

const ranges = computed(() =>
    (props.report?.ranges ?? [7, 30, 90]).map((days) => ({
        value: String(days),
        label: days === 7 ? 'Last 7 days' : `Last ${days} days`,
    })),
)

const hasTraffic = computed(() => series.value.some((day) => day.requests > 0))

const requestsDelta = computed(() => signedPercent(totals.value?.change?.requests_percent))

// A rise in traffic is not good or bad on its own, so the delta stays in plain
// ink rather than borrowing a status colour and implying a verdict.
const sampled = computed(() => (props.report?.sample_rate ?? 1) < 1)
</script>

<template>
    <div>
        <!-- Filters in one row above the charts, never interleaved with them. -->
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
            <Select
                :model-value="String(report?.range?.days ?? 7)"
                @update:model-value="emit('change-range', Number($event))"
            >
                <SelectTrigger class="w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="option in ranges" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <div class="inline-flex rounded-lg border p-0.5">
                <Button
                    :variant="view === 'chart' ? 'secondary' : 'ghost'"
                    size="sm"
                    class="h-7"
                    @click="view = 'chart'"
                >
                    <BarChart3 />
                    Chart
                </Button>
                <Button
                    :variant="view === 'table' ? 'secondary' : 'ghost'"
                    size="sm"
                    class="h-7"
                    @click="view = 'table'"
                >
                    <Table2 />
                    Table
                </Button>
            </div>
        </div>

        <DataState :loading="loading && !report" :error="error" @retry="emit('retry')" />

        <div v-if="report" class="space-y-6">
            <section aria-label="Traffic summary" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard
                    label="Requests"
                    :value="compact(totals.requests)"
                    :hint="requestsDelta ? `${requestsDelta} vs previous period` : 'No previous period'"
                />
                <StatCard
                    label="Busiest day"
                    :value="compact(totals.peak_visitors)"
                    hint="Unique visitors"
                />
                <StatCard
                    label="Error rate"
                    :value="percent(totals.error_rate)"
                    :role="totals.error_rate >= 5 ? 'critical' : totals.error_rate > 0 ? 'warning' : null"
                    :hint="`${compact(totals.server_errors)} server errors`"
                />
                <StatCard
                    label="Response time"
                    :value="milliseconds(totals.avg_response_time_ms)"
                    :hint="`${milliseconds(totals.p95_response_time_ms)} at the 95th percentile`"
                />
            </section>

            <p v-if="sampled" class="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
                This application samples successful requests at
                {{ percent(report.sample_rate * 100, 0) }}, so these counts are a sample rather than a
                total. Errors and slow requests are always reported in full.
            </p>

            <section aria-label="Requests per day" class="rounded-lg border bg-card p-4">
                <div class="flex items-baseline justify-between pb-3">
                    <h3 class="text-sm font-medium">Requests per day</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ report.range.from }} — {{ report.range.to }}
                    </span>
                </div>

                <DataState :empty="!hasTraffic" empty-message="No request traffic recorded in this period." />

                <template v-if="hasTraffic">
                    <StackedColumnChart v-if="view === 'chart'" :series="series" />

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Day</TableHead>
                                    <TableHead class="text-right">Requests</TableHead>
                                    <TableHead class="text-right">Success</TableHead>
                                    <TableHead class="text-right">Client errors</TableHead>
                                    <TableHead class="text-right">Server errors</TableHead>
                                    <TableHead class="text-right">Visitors</TableHead>
                                    <TableHead class="text-right">Avg</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="day in series" :key="day.day">
                                    <TableCell class="whitespace-nowrap">{{ dayFull(day.day) }}</TableCell>
                                    <TableCell class="text-right tabular-nums">{{ exact(day.requests) }}</TableCell>
                                    <TableCell class="text-right tabular-nums text-muted-foreground">
                                        {{ exact(day.success) }}
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums text-muted-foreground">
                                        {{ exact(day.client_errors) }}
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums text-muted-foreground">
                                        {{ exact(day.server_errors) }}
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums text-muted-foreground">
                                        {{ exact(day.unique_visitors) }}
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums text-muted-foreground">
                                        {{ milliseconds(day.avg_response_time_ms) }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </template>
            </section>

            <section aria-label="Unique visitors" class="rounded-lg border bg-card p-4">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-sm font-medium">Unique visitors</h3>
                    <span class="text-xs text-muted-foreground">
                        {{ compact(series.at(-1)?.unique_visitors) }} today
                    </span>
                </div>
                <!-- One series, so no legend: the heading already names it. -->
                <Sparkline :values="visitorTrend" class="pt-2" />
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section aria-label="Busiest endpoints" class="overflow-hidden rounded-lg border bg-card">
                    <h3 class="border-b p-4 text-sm font-medium">Busiest endpoints</h3>
                    <DataState
                        :empty="!report.top_endpoints.length"
                        empty-message="Nothing recorded yet."
                    />
                    <Table v-if="report.top_endpoints.length">
                        <TableBody>
                            <TableRow v-for="row in report.top_endpoints" :key="row.endpoint">
                                <TableCell class="max-w-[1px] truncate font-mono text-xs" :title="row.endpoint">
                                    {{ row.endpoint }}
                                </TableCell>
                                <TableCell class="w-24 text-right tabular-nums">{{ compact(row.requests) }}</TableCell>
                                <TableCell class="w-24 text-right text-xs tabular-nums text-muted-foreground">
                                    {{ row.errors > 0 ? `${compact(row.errors)} errors` : '—' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </section>

                <section aria-label="Busiest clients" class="overflow-hidden rounded-lg border bg-card">
                    <h3 class="border-b p-4 text-sm font-medium">Busiest clients</h3>
                    <DataState
                        :empty="!report.top_clients.length"
                        empty-message="No client addresses recorded."
                    />
                    <Table v-if="report.top_clients.length">
                        <TableBody>
                            <TableRow v-for="row in report.top_clients" :key="row.client_ip">
                                <TableCell class="font-mono text-xs">{{ row.client_ip }}</TableCell>
                                <TableCell class="w-24 text-right tabular-nums">{{ compact(row.requests) }}</TableCell>
                                <TableCell class="w-24 text-right text-xs tabular-nums text-muted-foreground">
                                    {{ row.errors > 0 ? `${compact(row.errors)} errors` : '—' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </section>
            </div>

            <slot name="footer" />
        </div>
    </div>
</template>
