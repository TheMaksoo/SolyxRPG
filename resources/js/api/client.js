import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});

// When a game endpoint returns 401 it could mean the session genuinely expired — but it could also
// be a transient server hiccup, CSRF token rotation, or a race condition. Immediately hard-redirecting
// to /landing on any 401 causes mid-game logouts for something that was never a real auth failure.
// Instead: confirm the session is actually dead by re-checking /me. Only redirect if /me also 401s.
// A single in-flight check is shared across any concurrent 401s so we never send a burst of /me calls.
let sessionCheckPromise = null;

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const url = error.config?.url || '';
        const isAuthEndpoint = url === '/me' || url.startsWith('/auth/');
        if (error.response?.status === 401 && !isAuthEndpoint) {
            if (!sessionCheckPromise) {
                sessionCheckPromise = axios
                    .get('/api/me', { withCredentials: true, headers: { Accept: 'application/json' } })
                    .then(() => {
                        // /me succeeded — session is alive, the original 401 was a fluke; do nothing.
                    })
                    .catch((meError) => {
                        if (meError.response?.status === 401) {
                            window.location.href = '/landing';
                        }
                    })
                    .finally(() => {
                        sessionCheckPromise = null;
                    });
            }
        }
        return Promise.reject(error);
    }
);

export async function ensureCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default api;
