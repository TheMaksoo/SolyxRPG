import { defineStore } from 'pinia';

/** Auto-Battle topbar countdown — a PURE display projection off Character::auto_battle_expires_at, never
 * its own network poll. AutoBattleController::show() (GET /auto-battle) has a side effect: it calls
 * AutoBattleService::tick(), which actually resolves queued fights (draining HP/MP, pushing new battle log
 * entries). Polling that endpoint from GameLayout just to show a topbar timer meant auto-battle was silently
 * ticking — and BattlePage silently re-rendering combat state — for as long as the app was open, even on
 * pages that have nothing to do with Battle. The countdown itself needs nothing but the fixed timestamp
 * every /character fetch already returns; GameLayout seeds this via a watch on that field, and BattlePage's
 * own existing poll (only while BattlePage is actually mounted) is the only thing allowed to tick fights. */
export const useAutoBattleStore = defineStore('autoBattle', {
    state: () => ({
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
        setExpiresAt(isoString) {
            this.expiresAtMs = isoString ? new Date(isoString).getTime() : null;
            this.now = Date.now();
        },
        tick() {
            this.now = Date.now();
        },
    },
});
