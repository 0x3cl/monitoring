import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

export const useApplicationsStore = defineStore('applications', {
    state: () => ({
        items: [],
        meta: null,
        servers: [],
        loading: false,
        error: null,
        filters: {
            search: '',
            environment: '',
            status: '',
            connection_status: '',
        },
    }),

    actions: {
        async fetch(page = 1) {
            this.loading = true
            this.error = null

            try {
                const params = { page }

                Object.entries(this.filters).forEach(([key, value]) => {
                    if (value !== '' && value !== null) {
                        params[key] = value
                    }
                })

                const { data } = await http.get('applications', { params })
                this.items = data.data
                this.meta = data.meta
            } catch (error) {
                this.error = toError(error)
            } finally {
                this.loading = false
            }
        },

        async fetchServers() {
            try {
                const { data } = await http.get('servers')
                this.servers = data.data
            } catch (error) {
                this.error = toError(error)
            }
        },

        /**
         * Registering returns the plaintext credential exactly once, so the
         * caller is responsible for showing it before navigating away.
         */
        async register(payload) {
            const { data } = await http.post('applications', payload)

            return data
        },

        async show(appCode) {
            const { data } = await http.get(`applications/${appCode}`)

            return data.data
        },

        async update(appCode, payload) {
            const { data } = await http.patch(`applications/${appCode}`, payload)

            return data.data
        },

        async destroy(appCode) {
            await http.delete(`applications/${appCode}`)
            this.items = this.items.filter((item) => item.app_code !== appCode)
        },

        async integration(appCode) {
            const { data } = await http.get(`applications/${appCode}/integration`)

            return data.data
        },

        /**
         * Rotates the credential and returns the setup with the key filled in.
         * The previous keys stop working, which is the only honest way to show
         * a complete setup again: stored keys are hashes.
         */
        async reissueIntegration(appCode) {
            const { data } = await http.post(`applications/${appCode}/integration/reissue`)

            return data
        },

        downloadUrl(appCode) {
            return `/api/admin/applications/${appCode}/integration/download`
        },

        async issueCredential(appCode, payload) {
            const { data } = await http.post(`applications/${appCode}/credentials`, payload)

            return data
        },

        async revokeCredential(credentialId) {
            const { data } = await http.delete(`credentials/${credentialId}`)

            return data.data
        },
    },
})
