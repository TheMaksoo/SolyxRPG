import { defineStore } from 'pinia';
import api from '../api/client';

let tickTimer = null;
let pollTimer = null;

/** Crafting queue status lifted into a global store purely so the topbar can show a live "time left on
 * your current craft" pill regardless of which page is open — CraftingPage keeps its own local queue
 * state for the full queue UI (it needs per-job detail this pill doesn't) and just calls refresh() after
 * craft()/collect() so the two never drift out of sync. Like autoGather.js, jobs carry an absolute
 * `completes_at`, so the countdown re-derives from that timestamp every tick rather than trusting a
 * relative "seconds remaining" number a slow poll could misreport. */
export const useCraftingQueueStore = defineStore('craftingQueue', {
    state: () => ({
        jobs: [],
        now: Date.now(),
        initialized: false,
    }),

    getters: {
        // Jobs run sequentially (see CraftingController::craft's queueFreeAt logic) — the first
        // not-yet-ready job is always the one currently cooking.
        activeJob: (state) => state.jobs.find((j) => !j.is_ready) ?? null,
        readyCount: (state) => state.jobs.filter((j) => j.is_ready).length,
        secondsRemaining() {
            const job = this.activeJob;
            return job ? Math.max(0, Math.round((new Date(job.completes_at).getTime() - this.now) / 1000)) : 0;
        },
    },

    actions: {
        async init() {
            if (this.initialized) return;
            this.initialized = true;
            tickTimer = setInterval(() => { this.now = Date.now(); }, 1000);
            await this.refresh();
        },

        async refresh() {
            try {
                const { data } = await api.get('/crafting/queue');
                this.jobs = data.jobs;
                this.now = Date.now();
            } catch {
                // No character yet / feature flag off — nothing to show.
            }
            this._syncPolling();
        },

        // Network polling only runs while a job is actually cooking, and even then it's a single shot
        // scheduled for exactly when that job's completes_at passes — not a repeating interval. This
        // used to hit /crafting/queue every 15s for the entire time anything sat in the queue (including
        // jobs that were already done and just waiting to be collected, which never needed polling at
        // all). CraftingPage already calls refresh() right after craft()/collect(), which re-arms this
        // the moment a new job actually starts cooking.
        _syncPolling() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
            const job = this.activeJob;
            if (job) {
                const delay = Math.max(250, new Date(job.completes_at).getTime() - this.now + 250);
                pollTimer = setTimeout(() => this.refresh(), delay);
            }
        },
    },
});
