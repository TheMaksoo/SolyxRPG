import { defineStore } from 'pinia';
import api from '../api/client';

// Same module-level singleton pattern as stores/autoGather.js / stores/pvpQueue.js — the topbar pill
// keeps counting down for as long as the app is open, regardless of which page is mounted.
let tickTimer = null;
let pollTimer = null;

/** Longest-remaining manual gather/smelt cooldown across all four trade skills, lifted into a global
 * store so the topbar can show it even when TradeSkillsPage isn't the mounted page — mirrors autoGather.js,
 * but for the per-click cooldown instead of the Auto-Gather pass. */
export const useGatherCooldownsStore = defineStore('gatherCooldowns', {
    state: () => ({
        longest: null, // { skill, label, glyph, targetLabel, expiresAtMs }
        now: Date.now(),
        initialized: false,
    }),

    getters: {
        secondsRemaining: (state) => (
            state.longest ? Math.max(0, Math.round((state.longest.expiresAtMs - state.now) / 1000)) : 0
        ),
    },

    actions: {
        // Called once from GameLayout on app mount — resumes showing an already-running cooldown after a
        // page refresh instead of the pill silently vanishing until the next visit to Gathering.
        async init() {
            if (this.initialized) return;
            this.initialized = true;
            await this.refresh();
            this._startPolling();
        },

        async refresh() {
            try {
                const { data } = await api.get('/trade-skills/cooldowns');
                this._applyList(data.cooldowns ?? []);
            } catch {
                // No character yet / feature flag off — nothing to show.
            }
        },

        // Called by TradeSkillsPage right after a load()/gather-batch resolves, using data it already has
        // in hand — avoids a redundant round-trip to /trade-skills/cooldowns just to learn what the page
        // itself already just fetched.
        setFromSkills(skills) {
            this._applyList(
                skills
                    .filter((s) => s.cooldown_remaining > 0 && s.last_action_target)
                    .map((s) => ({
                        skill: s.key,
                        label: s.label,
                        glyph: s.glyph,
                        target_label: s.targets.find((t) => t.key === s.last_action_target)?.label ?? s.last_action_target,
                        seconds_remaining: s.cooldown_remaining,
                    }))
            );
        },

        _applyList(list) {
            if (!list.length) {
                this.longest = null;
                return;
            }
            const top = list.reduce((a, b) => (b.seconds_remaining > a.seconds_remaining ? b : a));
            this.longest = {
                skill: top.skill,
                label: top.label,
                glyph: top.glyph,
                targetLabel: top.target_label,
                expiresAtMs: Date.now() + top.seconds_remaining * 1000,
            };
            this.now = Date.now();
        },

        _startPolling() {
            this._stopPolling();
            tickTimer = setInterval(() => { this.now = Date.now(); }, 1000);
            // A completed cooldown needs a real refresh (another target might already be on cooldown, or
            // the character switched pages and worked a different skill) — the tick alone can't know that.
            pollTimer = setInterval(() => this.refresh(), 5000);
        },

        _stopPolling() {
            clearInterval(tickTimer);
            clearInterval(pollTimer);
            tickTimer = null;
            pollTimer = null;
        },
    },
});
