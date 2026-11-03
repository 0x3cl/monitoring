import axios from 'axios'
import { defineStore } from 'pinia'
import http, { toError } from '@/lib/http'

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        checked: false,
        loading: false,
        error: null,
    }),

    getters: {
        isAuthenticated: (state) => state.user !== null,
        isAdmin: (state) => state.user?.is_admin === true,
    },

    actions: {
        /**
         * Establishes whether there is already a session, so a reload does not
         * bounce the administrator back to the login screen.
         */
        async check() {
            try {
                const { data } = await http.get('me')
                this.user = data.data
            } catch {
                this.user = null
            } finally {
                this.checked = true
            }
        },

        async login(email, password, remember = false) {
            this.loading = true
            this.error = null

            try {
                const { data } = await axios.post('/login', { email, password, remember })
                this.user = data.data

                return true
            } catch (error) {
                this.error = toError(error)

                return false
            } finally {
                this.loading = false
            }
        },

        async logout() {
            try {
                await axios.post('/logout')
            } finally {
                this.user = null
            }
        },
    },
})
