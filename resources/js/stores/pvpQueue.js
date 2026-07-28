import { defineStore } from 'pinia';
import api from '../api/client';

// Timers live outside state — not serializable/reactive data, same pattern as regen.js's module-level
// singletons — and must survive regardless of which component (if any) is currently mounted, since the
// whole point of this store is that the search keeps running while the player navigates away from /pvp.
let tickTimer = null;
let pollTimer = null;

/** Matchmaking queue state lifted out of PvpPage so "Find match" doesn't strand the player on a spinner —
 * this store polls independently of any page, and GameLayout (always mounted) surfaces a small persistent
 * pill + toast so the player can keep doing anything else and gets pulled back in the moment a match hits. */
export const usePvpQueueStore = defineStore('pvpQueue', {
    state: () => ({
        searching: false,
        elapsedSeconds: 0,
        // Wall-clock anchor (ms since epoch) the timer counts up from, kept in client time only — never
        // set directly to the server's elapsed_seconds. Network latency means that number is always
        // slightly stale by the time it arrives, so displaying it as-is made the timer visibly rewind by
        // a second on every 2.5s poll. Anchoring instead and only ever moving the anchor EARLIER (see
        // _anchorFrom) means the on-screen timer only ever counts forward.
        anchorMs: null,
        // Set the moment a match is found (by findMatch() or the background poll) and only ever replaced
        // by a newer match id — never cleared back to null — so a `watch` with no `immediate` still fires
        // exactly once per real match regardless of whether the player is on /pvp when it happens.
        matchFoundId: null,
        initialized: false,
    }),

    actions: {
        // Called once from GameLayout on app mount so a page refresh (or first load) while queued resumes
        // the search instead of silently dropping it.
        async init() {
            if (this.initialized) return;
            this.initialized = true;
            try {
                const { data } = await api.get('/pvp/queue/status');
                if (data.status === 'searching') {
                    this._anchorFrom(data.elapsed_seconds ?? 0);
                    this.searching = true;
                    this._startPolling();
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
                this._stopPolling();
                this.matchFoundId = data.match_id;
                return { matched: true, matchId: data.match_id };
            }

            this._anchorFrom(data.elapsed_seconds ?? 0);
            this.searching = true;
            this._startPolling();
            return { matched: false };
        },

        async cancelSearch() {
            this._stopPolling();
            this.searching = false;
            this.anchorMs = null;
            await api.post('/pvp/queue/leave');
        },

        // Anchors the timer so it reads `serverElapsedSeconds` right now, in client wall-clock time — but
        // only accepts the correction if it would push the anchor EARLIER (i.e. the timer forward), never
        // later. A perfectly-timed poll would compute the same anchor every time; a slow/late one always
        // underestimates elapsed time, so blindly re-anchoring on every poll made the display tick forward
        // and then visibly snap back a second whenever a poll landed a little late.
        _anchorFrom(serverElapsedSeconds) {
            const candidate = Date.now() - serverElapsedSeconds * 1000;
            this.anchorMs = this.anchorMs == null ? candidate : Math.min(this.anchorMs, candidate);
            this._recompute();
        },

        _recompute() {
            if (this.anchorMs == null) return;
            this.elapsedSeconds = Math.max(0, Math.floor((Date.now() - this.anchorMs) / 1000));
        },

        _startPolling() {
            this._stopPolling();
            tickTimer = setInterval(() => this._recompute(), 1000);
            pollTimer = setInterval(() => this._pollQueue(), 2500);
        },

        _stopPolling() {
            clearInterval(tickTimer);
            clearInterval(pollTimer);
            tickTimer = null;
            pollTimer = null;
        },

        async _pollQueue() {
            try {
                const { data } = await api.get('/pvp/queue/status');
                if (data.status === 'matched') {
                    this._stopPolling();
                    this.searching = false;
                    this.matchFoundId = data.match_id;
                } else if (data.status === 'searching') {
                    this._anchorFrom(data.elapsed_seconds ?? this.elapsedSeconds);
                } else {
                    // 'timeout' (searched too long, nobody found) or 'idle' (left from another tab) —
                    // either way, stop presenting the player as still searching.
                    this._stopPolling();
                    this.searching = false;
                }
            } catch {
                // Transient network hiccup — keep the pill up and try again next tick rather than
                // yanking the player out of the queue over one dropped request.
            }
        },
    },
});
