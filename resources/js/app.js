import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App.vue'
import router from '@/router'
import { useThemeStore } from '@/stores/theme'
import 'vue-sonner/style.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

// Applied before mount so the first paint is already in the right theme.
useThemeStore(pinia).init()

app.use(router).mount('#app')
