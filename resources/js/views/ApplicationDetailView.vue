<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, Download, KeyRound, RefreshCw, TriangleAlert } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import CopyField from '@/components/CopyField.vue'
import DataState from '@/components/DataState.vue'
import PageHeader from '@/components/PageHeader.vue'
import SeverityBadge from '@/components/SeverityBadge.vue'
import StatusIndicator from '@/components/StatusIndicator.vue'
import { useApplicationsStore } from '@/stores/applications'
import { useEventsStore } from '@/stores/events'
import { toError } from '@/lib/http'

const props = defineProps({
    appCode: { type: String, required: true },
})

const applications = useApplicationsStore()
const events = useEventsStore()

const application = ref(null)
const integration = ref(null)
const loading = ref(true)
const error = ref(null)
const rotated = ref(null)
const reissued = ref(null)
const reissuing = ref(false)

const healthRoles = { HEALTHY: 'good', WARNING: 'warning', CRITICAL: 'critical', UNKNOWN: 'neutral' }

async function load() {
    loading.value = true
    error.value = null

    try {
        application.value = await applications.show(props.appCode)
        integration.value = await applications.integration(props.appCode)

        events.filters.application = props.appCode
        await events.fetch(1)
    } catch (caught) {
        error.value = toError(caught)
    } finally {
        loading.value = false
    }
}

async function rotateKey() {
    try {
        const response = await applications.issueCredential(props.appCode, { rotate: true })
        rotated.value = response.credential
        application.value = await applications.show(props.appCode)
        toast.success('New key issued; the previous keys were revoked.')
    } catch (caught) {
        toast.error(toError(caught).message)
    }
}

/**
 * Fills the setup instructions in with a working key. This rotates rather
 * than reveals: the stored credential is a hash and cannot be read back.
 */
async function reissueSetup() {
    reissuing.value = true

    try {
        const response = await applications.reissueIntegration(props.appCode)
        integration.value = response.data
        reissued.value = response.credential
        application.value = await applications.show(props.appCode)
        toast.success('New key issued; the previous keys were revoked.')
    } catch (caught) {
        toast.error(toError(caught).message)
    } finally {
        reissuing.value = false
    }
}

async function revoke(credentialId) {
    try {
        await applications.revokeCredential(credentialId)
        application.value = await applications.show(props.appCode)
        toast.success('Credential revoked.')
    } catch (caught) {
        toast.error(toError(caught).message)
    }
}

onMounted(load)
</script>

<template>
    <div>
        <Button as-child variant="ghost" size="sm" class="-ml-2 mb-2">
            <RouterLink :to="{ name: 'applications' }">
                <ArrowLeft />
                Applications
            </RouterLink>
        </Button>

        <DataState :loading="loading" :error="error" @retry="load" />

        <template v-if="application && !loading">
            <PageHeader :title="application.name" :description="application.description ?? null">
                <template #actions>
                    <span class="font-mono text-sm text-muted-foreground">{{ application.app_code }}</span>
                    <StatusIndicator :role="healthRoles[application.health]" :label="application.health" />
                </template>
            </PageHeader>

            <dl class="grid grid-cols-2 gap-4 pb-6 text-sm lg:grid-cols-4">
                <div>
                    <dt class="text-xs tracking-wider text-muted-foreground uppercase">Environment</dt>
                    <dd class="mt-1">{{ application.environment }}</dd>
                </div>
                <div>
                    <dt class="text-xs tracking-wider text-muted-foreground uppercase">Status</dt>
                    <dd class="mt-1">{{ application.status_label }}</dd>
                </div>
                <div>
                    <dt class="text-xs tracking-wider text-muted-foreground uppercase">Server</dt>
                    <dd class="mt-1">{{ application.server?.name ?? 'Unassigned' }}</dd>
                </div>
                <div>
                    <dt class="text-xs tracking-wider text-muted-foreground uppercase">Last event</dt>
                    <dd class="mt-1 tabular-nums">
                        {{ application.last_event_at ? new Date(application.last_event_at).toLocaleString() : 'Never' }}
                    </dd>
                </div>
            </dl>

            <Tabs default-value="events">
                <TabsList>
                    <TabsTrigger value="events">Recent events</TabsTrigger>
                    <TabsTrigger value="integration">Integration</TabsTrigger>
                    <TabsTrigger value="credentials">Credentials</TabsTrigger>
                </TabsList>

                <TabsContent value="events">
                    <div class="overflow-hidden rounded-lg border bg-card">
                        <DataState
                            :empty="events.items.length === 0"
                            empty-message="No events received from this application yet."
                        />

                        <Table v-if="events.items.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Occurred</TableHead>
                                    <TableHead>Severity</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Endpoint</TableHead>
                                    <TableHead>Message</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="event in events.items.slice(0, 10)" :key="event.id">
                                    <TableCell class="tabular-nums whitespace-nowrap">
                                        {{ new Date(event.occurred_at).toLocaleString() }}
                                    </TableCell>
                                    <TableCell><SeverityBadge :severity="event.severity" /></TableCell>
                                    <TableCell class="text-muted-foreground">{{ event.event_type_label }}</TableCell>
                                    <TableCell class="font-mono text-xs">{{ event.endpoint ?? '—' }}</TableCell>
                                    <TableCell class="max-w-sm truncate text-muted-foreground">
                                        {{ event.message ?? '—' }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>

                        <div v-if="events.items.length" class="border-t px-4 py-3">
                            <Button as-child variant="link" size="sm" class="h-auto p-0">
                                <RouterLink :to="{ name: 'events', query: { application: application.app_code } }">
                                    View all events
                                </RouterLink>
                            </Button>
                        </div>
                    </div>
                </TabsContent>

                <TabsContent value="integration">
                    <div v-if="integration" class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-muted-foreground">
                                {{ integration.integration_type_name }} integration
                                <template v-if="integration.package_name">
                                    · <code class="font-mono">{{ integration.package_name }}</code>
                                </template>
                            </p>
                            <div class="flex items-center gap-2">
                                <Button v-if="integration.has_download" as-child variant="ghost" size="sm">
                                    <a :href="applications.downloadUrl(appCode)">
                                        <Download />
                                        Download agent
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" :disabled="reissuing" @click="reissueSetup">
                                    <RefreshCw />
                                    {{ reissuing ? 'Issuing…' : 'New key & full setup' }}
                                </Button>
                            </div>
                        </div>

                        <p v-if="integration.repository_url" class="text-sm text-muted-foreground">
                            The agent is served by this SENTINEL instance as a Composer repository
                            (<code class="font-mono">{{ integration.repository_url }}</code>), so there is nothing to
                            upload or host elsewhere.
                        </p>

                        <!--
                            Without a key the steps still read correctly; they
                            carry a placeholder rather than a working value.
                        -->
                        <div
                            v-if="!reissued"
                            class="flex items-start gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0"
                                :style="{ color: 'var(--status-warning)' }"
                                aria-hidden="true"
                            />
                            <span>
                                The application key is stored as a hash and cannot be shown again. These steps use a
                                placeholder — use <strong>New key &amp; full setup</strong> to issue a replacement
                                with the key filled in, which revokes the current one.
                            </span>
                        </div>

                        <Card v-else>
                            <CardContent class="space-y-2">
                                <p class="text-sm text-muted-foreground">{{ reissued.warning }}</p>
                                <CopyField title="Application key" :content="reissued.token" />
                            </CardContent>
                        </Card>

                        <CopyField
                            v-for="(step, index) in integration.instructions"
                            :key="index"
                            :title="`${index + 1}. ${step.title}`"
                            :content="step.body"
                        />
                    </div>
                </TabsContent>

                <TabsContent value="credentials">
                    <div class="space-y-4">
                        <div class="flex justify-end">
                            <Button variant="outline" size="sm" @click="rotateKey">
                                <RefreshCw />
                                Rotate key
                            </Button>
                        </div>

                        <Card v-if="rotated">
                            <CardContent class="space-y-2">
                                <p class="text-sm text-muted-foreground">{{ rotated.warning }}</p>
                                <CopyField title="New application key" :content="rotated.token" />
                            </CardContent>
                        </Card>

                        <div class="overflow-hidden rounded-lg border bg-card">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Key</TableHead>
                                        <TableHead>State</TableHead>
                                        <TableHead>Last used</TableHead>
                                        <TableHead class="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="credential in application.credentials" :key="credential.id">
                                        <TableCell class="font-mono text-xs">
                                            <span class="inline-flex items-center gap-2">
                                                <KeyRound class="size-3.5 text-muted-foreground" aria-hidden="true" />
                                                {{ credential.masked_token }}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <StatusIndicator
                                                :role="credential.is_usable ? 'good' : 'neutral'"
                                                :label="
                                                    credential.is_revoked
                                                        ? 'Revoked'
                                                        : credential.is_expired
                                                          ? 'Expired'
                                                          : 'Active'
                                                "
                                            />
                                        </TableCell>
                                        <TableCell class="tabular-nums text-muted-foreground">
                                            {{
                                                credential.last_used_at
                                                    ? new Date(credential.last_used_at).toLocaleString()
                                                    : 'Never'
                                            }}
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <Button
                                                v-if="credential.is_usable"
                                                variant="ghost"
                                                size="xs"
                                                @click="revoke(credential.id)"
                                            >
                                                Revoke
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </template>
    </div>
</template>
