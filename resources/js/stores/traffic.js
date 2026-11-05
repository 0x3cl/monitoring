import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

export const useTrafficStore = defineStore('traffic', {
    state: () => ({
        report: null,
        days: 7,
        appCode: null,
        loading: false,
        error: null,
    }),

    actions: {
        async fetch({ quiet = false } = {}) {
            if (!quiet) {
                this.loading = true
            }

            this.error = null

            try {
                const params = { days: this.days }

                if (this.appCode) {
                    params.application = this.appCode
                }

                const { data } = await http.get('traffic', { params })
                this.report = data.data
            } catch (error) {
                this.error = toError(error)
            } finally {
                this.loading = false
            }
        },

        setRange(days) {
            this.days = days

            return this.fetch()
        },

        /**
         * Rebuilds the rollups from raw events. Queued, so the dashboard does
         * not wait on a scan of the events table.
         */
        async refresh(appCode) {
            const { data } = await http.post(`applications/${appCode}/traffic/refresh`)

            return data
        },
    },
})
