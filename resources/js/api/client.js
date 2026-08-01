import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

// The router only checks auth once per SPA load and then trusts it for the whole session — if the
// session dies underneath the user (timeout, server restart, cookie cleared) nothing re-validates it,
// so every subsequent action just fails with a confusing per-page error. Catch that here instead: any
// 401 from a real (already-past-the-login-gate) request forces a full reload back to the login screen,
// which also wipes all stale client-side state. /me and /auth/* are excluded since their 401s are the
// normal "not logged in yet" case already handled by the router guard.
api.interceptors.response.use(
    (response) => response,
    (error) => {
        const url = error.config?.url || '';
        const isAuthEndpoint = url.startsWith('/me') || url.startsWith('/auth/');
        if (error.response?.status === 401 && !isAuthEndpoint) {
            window.location.href = '/landing';
        }
        return Promise.reject(error);
    }
);

export async function ensureCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default api;
