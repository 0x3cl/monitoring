<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ArrowLeft, CheckCircle2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import CopyField from '@/components/CopyField.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useApplicationsStore } from '@/stores/applications'
import { useMetaStore } from '@/stores/meta'
import { toError } from '@/lib/http'

const applications = useApplicationsStore()
const meta = useMetaStore()
const router = useRouter()

const form = reactive({
    name: '',
    environment: 'production',
    integration_type_id: null,
    server_id: null,
    base_url: '',
    description: '',
})

const submitting = ref(false)
const error = ref(null)
// Set only after a successful registration: the credential is shown here
// once and is never retrievable again.
const result = ref(null)

onMounted(async () => {
    await meta.load()
    applications.fetchServers()

    const laravel = meta.availableIntegrationTypes.find((type) => type.slug === 'laravel')
    form.integration_type_id = laravel?.id ?? meta.availableIntegrationTypes[0]?.id ?? null
})

async function submit() {
    submitting.value = true
    error.value = null

    try {
        result.value = await applications.register({
            ...form,
            base_url: form.base_url || null,
            description: form.description || null,
            server_id: form.server_id || null,
        })
    } catch (caught) {
        error.value = toError(caught)
    } finally {
        submitting.value = false
    }
}

function fieldError(field) {
    return error.value?.errors?.[field]?.[0]
}
</script>

<template>
    <div class="max-w-2xl">
        <Button as-child variant="ghost" size="sm" class="-ml-2 mb-2">
            <RouterLink :to="{ name: 'applications' }">
                <ArrowLeft />
                Applications
            </RouterLink>
        </Button>

        <!-- Success: the single opportunity to copy the credential. -->
        <template v-if="result">
            <PageHeader :title="result.data.name" :description="`Registered as ${result.data.app_code}`" />

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <CheckCircle2 class="size-4" :style="{ color: 'var(--status-good)' }" aria-hidden="true" />
                        Installation
                    </CardTitle>
                    <CardDescription>{{ result.credential.warning }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <CopyField title="Application key" :content="result.credential.token" />
                    <CopyField
                        v-for="(step, index) in result.integration.instructions"
                        :key="index"
                        :title="`${index + 1}. ${step.title}`"
                        :content="step.body"
                    />

                    <div class="flex gap-2 pt-2">
                        <Button as-child>
                            <RouterLink
                                :to="{ name: 'applications.show', params: { appCode: result.data.app_code } }"
                            >
                                Open application
                            </RouterLink>
                        </Button>
                        <Button variant="outline" @click="router.push({ name: 'applications' })">Done</Button>
                    </div>
                </CardContent>
            </Card>
        </template>

        <template v-else>
            <PageHeader title="Register application" description="No code change is needed on the engine side." />

            <Card>
                <CardContent>
                    <form class="space-y-5" @submit.prevent="submit">
                        <div class="space-y-2">
                            <Label for="name">Application name</Label>
                            <Input id="name" v-model="form.name" required placeholder="Finance Management System" />
                            <p v-if="fieldError('name')" class="text-xs" :style="{ color: 'var(--status-critical)' }">
                                {{ fieldError('name') }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="environment">Environment</Label>
                                <Select v-model="form.environment">
                                    <SelectTrigger id="environment" class="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in meta.environments"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="space-y-2">
                                <Label for="integration">Application type</Label>
                                <Select
                                    :model-value="form.integration_type_id ? String(form.integration_type_id) : ''"
                                    @update:model-value="form.integration_type_id = Number($event)"
                                >
                                    <SelectTrigger id="integration" class="w-full">
                                        <SelectValue placeholder="Select a type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="type in meta.availableIntegrationTypes"
                                            :key="type.id"
                                            :value="String(type.id)"
                                        >
                                            {{ type.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p class="text-xs text-muted-foreground">
                                    Integrations still in development are not listed.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="server">Hosting server</Label>
                                <Select
                                    :model-value="form.server_id ? String(form.server_id) : 'none'"
                                    @update:model-value="form.server_id = $event === 'none' ? null : Number($event)"
                                >
                                    <SelectTrigger id="server" class="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Unassigned</SelectItem>
                                        <SelectItem
                                            v-for="server in applications.servers"
                                            :key="server.id"
                                            :value="String(server.id)"
                                        >
                                            {{ server.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="space-y-2">
                                <Label for="base_url">Base URL</Label>
                                <Input
                                    id="base_url"
                                    v-model="form.base_url"
                                    type="url"
                                    placeholder="https://finance.example.com"
                                />
                                <p
                                    v-if="fieldError('base_url')"
                                    class="text-xs"
                                    :style="{ color: 'var(--status-critical)' }"
                                >
                                    {{ fieldError('base_url') }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="description">Description</Label>
                            <Input id="description" v-model="form.description" />
                        </div>

                        <p
                            v-if="error && !Object.keys(error.errors ?? {}).length"
                            class="text-xs"
                            :style="{ color: 'var(--status-critical)' }"
                            role="alert"
                        >
                            {{ error.message }}
                        </p>

                        <Button type="submit" :disabled="submitting">
                            {{ submitting ? 'Registering…' : 'Register application' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
