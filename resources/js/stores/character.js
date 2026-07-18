import { defineStore } from 'pinia';
import api from '../api/client';
import { useAuthStore } from './auth';

export const useCharacterStore = defineStore('character', {
    state: () => ({
        character: null,
        stats: null,
        loading: false,
    }),

    actions: {
        async fetch() {
            this.loading = true;
            try {
                const { data } = await api.get('/character');
                this.character = data.character;
                this.stats = data.stats;
            } finally {
                this.loading = false;
            }
        },

        async create(payload) {
            const { data } = await api.post('/character', payload);
            this.character = data.character;
            useAuthStore().setCharacter(data.character);
        },

        async spendAttribute(attr) {
            const { data } = await api.post('/character/attributes', { attr });
            this.character = data.character;
            this.stats = data.stats;
        },

        async unlockSkill(skillId) {
            const { data } = await api.post(`/character/skills/${skillId}`);
            this.character = data.character;
        },

        async chooseProfession(tier, key) {
            const { data } = await api.post('/character/profession', { tier, key });
            this.character = data.character;
        },
    },
});
