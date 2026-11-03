import { defineStore } from 'pinia'

const STORAGE_KEY = 'sentinel-theme'
const MODES = ['light', 'dark', 'system']

function prefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function stored() {
    try {
        const value = localStorage.getItem(STORAGE_KEY)

        return MODES.includes(value) ? value : 'system'
    } catch {
        // Private browsing and blocked site data both throw here; the default
        // mode keeps the dashboard usable either way.
        return 'system'
    }
}

export const useThemeStore = defineStore('theme', {
    state: () => ({
        mode: stored(),
    }),

    getters: {
        isDark: (state) => (state.mode === 'system' ? prefersDark() : state.mode === 'dark'),
    },

    actions: {
        /**
         * Applies the mode to <html> and starts following the OS setting while
         * the mode is 'system'.
         */
        init() {
            this.apply()

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                if (this.mode === 'system') {
                    this.apply()
                }
            })
        },

        set(mode) {
            this.mode = MODES.includes(mode) ? mode : 'system'

            try {
                localStorage.setItem(STORAGE_KEY, this.mode)
            } catch {
                // Not persisting is survivable; the session still honours it.
            }

            this.apply()
        },

        apply() {
            document.documentElement.classList.toggle('dark', this.isDark)
        },
    },
})
