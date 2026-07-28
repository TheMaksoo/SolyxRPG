import { defineStore } from 'pinia';

/** Auto-Gather topbar countdown — a PURE display projection, never its own network poll.
 * AutoGatherController::show() (GET /auto-gather) has a side effect: it calls AutoGatherService::tick(),
 * which actually consumes Energy/materials for queued gathering. Polling that endpoint from GameLayout
 * just to show a topbar timer meant Energy was silently draining on every page, not just Gathering.
 * GameLayout seeds this from Character::auto_gather_expires_at/skill/target (already returned by every
 * /character fetch, no extra request), and TradeSkillsPage pushes fresher data in directly whenever its
 * own on-page poll (the only thing allowed to tick gathering) or a purchase/switch resolves. */
export const useAutoGatherStore = defineStore('autoGather', {
    state: () => ({
        skill: null,
        target: null,
        expiresAtMs: null,
        now: Date.now(),
    }),

    getters: {
        active: (state) => state.expiresAtMs != null && state.expiresAtMs > state.now,
        secondsRemaining: (state) => (
            state.expiresAtMs != null ? Math.max(0, Math.round((state.expiresAtMs - state.now) / 1000)) : 0
        ),
    },

    actions: {
        // Seeds from the Character fields every /character fetch already includes — used once at app
        // mount (before the player has necessarily ever visited Gathering this session).
        setFromCharacter(character) {
            this.skill = character?.auto_gather_skill ?? null;
            this.target = character?.auto_gather_target ?? null;
            this.expiresAtMs = character?.auto_gather_expires_at ? new Date(character.auto_gather_expires_at).getTime() : null;
            this.now = Date.now();
        },

        // Called by TradeSkillsPage with data it already fetched (loadAutoGather / purchase / switch) —
        // this store never fetches that data itself.
        setFromResponse(data) {
            this.skill = data.skill ?? null;
            this.target = data.target ?? null;
            this.expiresAtMs = data.active !== false && data.expires_at ? new Date(data.expires_at).getTime() : null;
            this.now = Date.now();
        },

        tick() {
            this.now = Date.now();
        },
    },
});
