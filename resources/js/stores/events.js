import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

export const useEventsStore = defineStore('events', {
    state: () => ({
        items: [],
        meta: null,
        loading: false,
        error: null,
        filters: {
            application: '',
            severity: '',
            event_type: '',
            endpoint: '',
            search: '',
        },
    }),

    actions: {
        /**
         * `quiet` refreshes in the background for polling, leaving the table
         * on screen instead of flashing a loading state every few seconds.
         */
        async fetch(page = 1, { quiet = false } = {}) {
            if (!quiet) {
                this.loading = true
            }

            this.error = null

            try {
                const params = { page }

                Object.entries(this.filters).forEach(([key, value]) => {
                    if (value !== '' && value !== null) {
                        params[key] = value
                    }
                })

                const { data } = await http.get('events', { params })
                this.items = data.data
                this.meta = data.meta
            } catch (error) {
                this.error = toError(error)
            } finally {
                this.loading = false
            }
        },
    },
})
