import { defineStore } from 'pinia';
import api, { ensureCsrfCookie } from '../api/client';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        checked: false,
    }),

    getters: {
        isAuthenticated: (state) => !!state.user,
        hasCharacter: (state) => !!state.user?.character,
    },

    actions: {
        async fetchMe() {
            try {
                const { data } = await api.get('/me');
                this.user = data.user;
            } catch {
                this.user = null;
            } finally {
                this.checked = true;
            }
        },

        async register({ name, email, password }) {
            await ensureCsrfCookie();
            const { data } = await api.post('/auth/register', { name, email, password });
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
