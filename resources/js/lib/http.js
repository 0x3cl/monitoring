import axios from 'axios'

/**
 * Shared API client.
 *
 * The dashboard is served from the same origin as the API, so authentication
 * rides on the session cookie and CSRF protection applies as usual.
 */
const http = axios.create({
    baseURL: '/api/admin',
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
})

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content

if (csrfToken) {
    http.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken
}

axios.defaults.withCredentials = true
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

/**
 * Normalizes an axios error into a message the UI can show directly, plus
 * per-field validation errors when the server returned them.
 */
export function toError(error) {
    const status = error.response?.status ?? 0
    const data = error.response?.data ?? {}

    return {
        status,
        message: data.message || error.message || 'Something went wrong.',
        errors: data.errors ?? {},
    }
}

export default http
