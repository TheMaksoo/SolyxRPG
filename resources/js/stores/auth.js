import { defineStore } from 'pinia';
import api, { ensureCsrfCookie } from '../api/client';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        checked: false,
        globalTesterMode: false,
        featureAccess: {},
    }),

    getters: {
        isAuthenticated: (state) => !!state.user,
        hasCharacter: (state) => !!state.user?.character,
    },

    actions: {
        async fetchMe() {
            try {
                const { data } = await api.get('/me');
                this.user = { ...data.user, has_password: data.has_password };
                this.globalTesterMode = data.global_tester_mode;
                this.featureAccess = data.feature_access || {};
            } catch {
                this.user = null;
            } finally {
                this.checked = true;
            }
        },

        async register({ name, email, password, tos_accepted, cf_turnstile_response, referral_code }) {
            await ensureCsrfCookie();
            const { data } = await api.post('/auth/register', { name, email, password, tos_accepted, cf_turnstile_response, referral_code });
            this.user = data.user;
            this.checked = true;
        },

        async login({ email, password }) {
            await ensureCsrfCookie();
            const { data } = await api.post('/auth/login', { email, password });
            this.user = data.user;
            this.checked = true;
        },

        async logout() {
            await api.post('/auth/logout');
            this.user = null;
        },

        setCharacter(character) {
            if (this.user) this.user.character = character;
        },
    },
});
