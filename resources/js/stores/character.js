import { defineStore } from 'pinia';
import api from '../api/client';
import { useAuthStore } from './auth';

export const useCharacterStore = defineStore('character', {
    state: () => ({
        character: null,
        stats: null,
        loading: false,
        slots: null,
        regenPerTick: 0,
        manaRegenPerTick: 0,
        energyRegenPerTick: 0,
        inCombat: false,
        attributeCosts: null,
    }),

    actions: {
        async fetch() {
            this.loading = true;
            try {
                const { data } = await api.get('/character');
                this.character = data.character;
                this.stats = data.stats;
                this.regenPerTick = data.regen_per_tick ?? 0;
                this.manaRegenPerTick = data.mana_regen_per_tick ?? 0;
                this.energyRegenPerTick = data.energy_regen_per_tick ?? 0;
                this.inCombat = data.in_combat ?? false;
                this.attributeCosts = data.attribute_costs ?? null;
            } finally {
                this.loading = false;
            }
        },

        async create(payload) {
            const { data } = await api.post('/character', payload);
            this.character = data.character;
            useAuthStore().setCharacter(data.character);
        },

        async fetchSlots() {
            const { data } = await api.get('/characters');
            this.slots = data;
            return data;
        },

        async select(characterId) {
            const { data } = await api.post(`/characters/${characterId}/select`);
            this.character = data.character;
            this.stats = data.stats;
            useAuthStore().setCharacter(data.character);
        },

        async remove(characterId) {
            const { data } = await api.delete(`/characters/${characterId}`);
            if (!data.active_character_id) {
                this.character = null;
                this.stats = null;
                useAuthStore().setCharacter(null);
            }
            return data;
        },

        async unlockSlot(characterId) {
            const { data } = await api.post('/characters/slots/unlock', { character_id: characterId });
            return data;
        },

        async spendAttribute(attr) {
            const { data } = await api.post('/character/attributes', { attr });
            this.character = data.character;
            this.stats = data.stats;
            this.attributeCosts = data.attribute_costs ?? this.attributeCosts;
        },

        async unlockSkill(skillId) {
            const { data } = await api.post(`/character/skills/${skillId}`);
            this.character = data.character;
        },

        async chooseProfession(tier, key) {
            const { data } = await api.post('/character/profession', { tier, key });
            this.character = data.character;
        },

        async usePotion(itemId) {
            const { data } = await api.post('/inventory/use', { item_id: itemId });
            this.character = data.character;
            return data.applied;
        },
    },
});
