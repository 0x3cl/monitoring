import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

/**
 * Event vocabularies and the integration catalogue, served by the backend so
 * the dashboard never hardcodes the event schema.
 */
export const useMetaStore = defineStore('meta', {
    state: () => ({
        severities: [],
        eventTypes: [],
        environments: [],
        applicationStatuses: [],
        connectionStatuses: [],
        healthStates: [],
        integrationTypes: [],
        loaded: false,
        error: null,
    }),

    getters: {
        availableIntegrationTypes: (state) => state.integrationTypes.filter((type) => type.is_available),
    },

    actions: {
        async load(force = false) {
            if (this.loaded && !force) {
                return
            }

            try {
                const { data } = await http.get('meta')

                this.severities = data.data.severities
                this.eventTypes = data.data.event_types
                this.environments = data.data.environments
                this.applicationStatuses = data.data.application_statuses
                this.connectionStatuses = data.data.connection_statuses
                this.healthStates = data.data.health_states
                this.integrationTypes = data.data.integration_types
                this.loaded = true
                this.error = null
            } catch (error) {
                this.error = toError(error)
            }
        },
    },
})
