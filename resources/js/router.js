import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/views/LoginView.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        component: () => import('@/layouts/DashboardLayout.vue'),
        children: [
            { path: '', name: 'overview', component: () => import('@/views/OverviewView.vue') },
            { path: 'applications', name: 'applications', component: () => import('@/views/ApplicationsView.vue') },
            {
                path: 'applications/register',
                name: 'applications.register',
                component: () => import('@/views/RegisterApplicationView.vue'),
            },
            {
                path: 'applications/:appCode',
                name: 'applications.show',
                component: () => import('@/views/ApplicationDetailView.vue'),
                props: true,
            },
            { path: 'traffic', name: 'traffic', component: () => import('@/views/TrafficView.vue') },
            { path: 'events', name: 'events', component: () => import('@/views/EventsView.vue') },
            { path: 'servers', name: 'servers', component: () => import('@/views/ServersView.vue') },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: { name: 'overview' } },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    if (!auth.checked) {
        await auth.check()
    }

    if (!to.meta.guest && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guest && auth.isAuthenticated) {
        return { name: 'overview' }
    }

    return true
})

export default router
