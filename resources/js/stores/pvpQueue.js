import { defineStore } from 'pinia';
import api from '../api/client';
import { useEcho } from '../echo';
import { useAuthStore } from './auth';

// Only the 1s local countdown timer lives outside state now — not serializable/reactive data, same
// pattern as regen.js's module-level singletons. The 2.5s queue-status poll this used to also drive is
// gone entirely; matching is now pushed via the user's own private "pvp" channel (see init() below).
let tickTimer = null;

/** Matchmaking queue state lifted out of PvpPage so "Find match" doesn't strand the player on a spinner —
 * this store reacts to a push the moment matched, independent of any page, and GameLayout (always
 * mounted) surfaces a small persistent pill + toast so the player can keep doing anything else. */
export const usePvpQueueStore = defineStore('pvpQueue', {
    state: () => ({
        searching: false,
        elapsedSeconds: 0,
        // Wall-clock anchor (ms since epoch) the timer counts up from, kept in client time only — never
        // set directly to the server's elapsed_seconds. Network latency means that number is always
        // slightly stale by the time it arrives, so displaying it as-is made the timer visibly rewind by
        // a second on every poll. Anchoring instead and only ever moving the anchor EARLIER (see
        // _anchorFrom) means the on-screen timer only ever counts forward.
        anchorMs: null,
        // Set the moment a match is found (by findMatch() or the push listener below) and only ever
        // replaced by a newer match id — never cleared back to null — so a `watch` with no `immediate`
        // still fires exactly once per real match regardless of whether the player is on /pvp when it happens.
        matchFoundId: null,
        initialized: false,
    }),

    actions: {
        // Called once from GameLayout on app mount so a page refresh (or first load) while queued resumes
        // the search instead of silently dropping it — this one status check is still a plain fetch since
        // a fresh page load has no live connection yet to have caught a push. Also subscribes once to the
        // user's own private pvp channel for the lifetime of the session.
        async init() {
            if (this.initialized) return;
            this.initialized = true;

            const auth = useAuthStore();
            if (auth.user) {
                const channel = useEcho().private(`user.${auth.user.id}.pvp`);
                channel.listen('.pvp.match-found', (e) => {
                    this._stopTick();
                    this.searching = false;
                    this.matchFoundId = e.match_id;
                });
                // Replaces what the old 2.5s poll used to catch inline when the server gave up on a
                // 5-minute-stale queue entry (see PvpMatchmakingService::purgeStaleEntries) — without
                // this, a timed-out search would leave the "Searching…" pill up forever.
                channel.listen('.pvp.queue-timeout', () => {
                    this._stopTick();
                    this.searching = false;
                });
            }

            try {
                const { data } = await api.get('/pvp/queue/status');
                if (data.status === 'searching') {
                    this._anchorFrom(data.elapsed_seconds ?? 0);
                    this.searching = true;
                    this._startTick();
                } else if (data.status === 'matched') {
                    this.matchFoundId = data.match_id;
                }
            } catch {
                // No character yet / feature flag off — nothing to resume.
            }
        },

        async findMatch() {
            const { data } = await api.post('/pvp/queue/join');
            if (data.status === 'matched') {
                this.searching = false;
                this._stopTick();
                this.matchFoundId = data.match_id;
                return { matched: true, matchId: data.match_id };
            }

            this._anchorFrom(data.elapsed_seconds ?? 0);
            this.searching = true;
            this._startTick();
            return { matched: false };
        },

        async cancelSearch() {
            this._stopTick();
            this.searching = false;
            this.anchorMs = null;
            await api.post('/pvp/queue/leave');
        },

        // Anchors the timer so it reads `serverElapsedSeconds` right now, in client wall-clock time — but
        // only accepts the correction if it would push the anchor EARLIER (i.e. the timer forward), never
        // later.
        _anchorFrom(serverElapsedSeconds) {
            const candidate = Date.now() - serverElapsedSeconds * 1000;
            this.anchorMs = this.anchorMs == null ? candidate : Math.min(this.anchorMs, candidate);
            this._recompute();
        },

        _recompute() {
            if (this.anchorMs == null) return;
            this.elapsedSeconds = Math.max(0, Math.floor((Date.now() - this.anchorMs) / 1000));
        },

        _startTick() {
            this._stopTick();
            tickTimer = setInterval(() => this._recompute(), 1000);
        },

        _stopTick() {
            clearInterval(tickTimer);
            tickTimer = null;
        },
    },
});
