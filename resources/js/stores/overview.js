import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

export const useOverviewStore = defineStore('overview', {
    state: () => ({
        data: null,
        windowMinutes: 1440,
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
                const { data } = await http.get('overview', {
                    params: { window_minutes: this.windowMinutes },
                })
                this.data = data.data
            } catch (error) {
                this.error = toError(error)
            } finally {
                this.loading = false
            }
        },
    },
})
