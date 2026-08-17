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
                // BattlePage fires this after nearly every battle action, so it's common for one of these
                // requests to be in flight when the tutorial overlay's dismiss/advance call lands — if that
                // fetch was dispatched just before the server persisted the new tutorial_step, its response
                // still carries the OLD value and would silently roll the tour back a step (or reopen it
                // entirely) the moment it overwrites `this.character`. tutorial_step/tutorial_seen only ever
                // move forward, so it's always safe to keep whichever value is more advanced.
                const priorTutorialStep = this.character?.tutorial_step ?? 0;
                const priorTutorialSeen = this.character?.tutorial_seen ?? false;
                const { data } = await api.get('/character');
                this.character = data.character;
                if (this.character) {
                    this.character.tutorial_step = Math.max(priorTutorialStep, this.character.tutorial_step ?? 0);
                    this.character.tutorial_seen = priorTutorialSeen || this.character.tutorial_seen;
                }
                useAuthStore().setCharacter(data.character);
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

        async setActiveDeck(skillIds) {
            const { data } = await api.post('/character/skills/deck', { skill_ids: skillIds });
            this.character = data.character;
        },

        async respecSkills() {
            const { data } = await api.post('/character/skills/respec');
            this.character = data.character;
        },

        async usePotion(itemId) {
            const { data } = await api.post('/inventory/use', { item_id: itemId });
            this.character = data.character;
            return data.applied;
        },

        // The one periodic "game save" push (composables/regen.js calls this every ~5 real seconds) —
        // sends what the client already knows it's showing (it computes the same regen formula the
        // server does, just continuously) so the server persists that exact value (clamped to what
        // could legitimately have regenerated, see Character::maxPlausibleValue) instead of silently
        // overwriting it with its own independently-rounded recompute. Every other character-related
        // request only ever reads/fetches; this is the only one that writes on a normal timer.
        async syncRegen(payload = {}) {
            const { data } = await api.post('/character/save-tick', payload);
            if (this.character) {
                this.character.hp = data.hp;
                this.character.mana = data.mana;
                this.character.energy = data.energy;
            }
            this.regenPerTick = data.regen_per_tick ?? this.regenPerTick;
            this.manaRegenPerTick = data.mana_regen_per_tick ?? this.manaRegenPerTick;
            this.energyRegenPerTick = data.energy_regen_per_tick ?? this.energyRegenPerTick;
            return data;
        },
    },
});
