<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const characterStore = useCharacterStore();

// ---- Lobby state (rank card + opponent list + history) ----
const record = ref(null);
const rank = ref(null);
const rankProgress = ref(null);
const rankLadder = ref([]);
const opponents = ref([]);
const history = ref([]);
const loading = ref(false);
const errorMessage = ref('');

// 'lobby' | 'searching' | 'live'
const view = ref('lobby');

// ---- Queue state ----
const queuedAt = ref(null);
const elapsedSeconds = ref(0);
let queuePollTimer = null;
let queueTickTimer = null;

// ---- Live match state ----
const matchId = ref(null);
const match = ref(null);
let livePollTimer = null;

// Mirrors $success / $warning / $purple in resources/scss/_variables.scss.
const difficultyColor = {
  Easy: '#4ade80',
  Medium: '#eab308',
  Hard: '#a78bfa',
};

async function load() {
  const { data } = await api.get('/pvp');
  record.value = data.record;
  rank.value = data.rank;
  rankProgress.value = data.rank_progress;
  rankLadder.value = data.rank_ladder;
  opponents.value = data.opponents;
  history.value = data.history;

  if (data.active_match_id) {
    matchId.value = data.active_match_id;
    enterLive();
  } else if (data.queued) {
    enterSearching();
  }
}

function syncCharacter(character) {
  if (character) {
    characterStore.character = character;
  }
}

function stopAllPolling() {
  clearInterval(queuePollTimer);
  clearInterval(queueTickTimer);
  clearInterval(livePollTimer);
  queuePollTimer = null;
  queueTickTimer = null;
  livePollTimer = null;
}

// ---- Queue flow ----

async function findMatch() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post('/pvp/queue/join');
    if (data.status === 'matched') {
      matchId.value = data.match_id;
      enterLive();
    } else {
      queuedAt.value = data.queued_at;
      elapsedSeconds.value = Math.max(0, Math.floor(data.elapsed_seconds ?? 0));
      enterSearching();
    }
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}

function enterSearching() {
  stopAllPolling();
  view.value = 'searching';
  queueTickTimer = setInterval(() => {
    elapsedSeconds.value += 1;
  }, 1000);
  queuePollTimer = setInterval(pollQueue, 2500);
}

async function pollQueue() {
  try {
    const { data } = await api.get('/pvp/queue/status');
    if (data.status === 'matched') {
      matchId.value = data.match_id;
      enterLive();
    } else if (data.status === 'searching') {
      queuedAt.value = data.queued_at;
      elapsedSeconds.value = Math.max(0, Math.floor(data.elapsed_seconds ?? elapsedSeconds.value));
    } else if (data.status === 'timeout') {
      // Searched for over an hour with nobody found — stop, and say so plainly instead of leaving the
      // player staring at a spinner forever.
      stopAllPolling();
      view.value = 'lobby';
      errorMessage.value = 'No rival nearby right now. Try again in a bit.';
      await load();
    } else {
      // 'idle' — queue row vanished without a match (e.g. left from another tab).
      stopAllPolling();
      view.value = 'lobby';
    }
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Lost connection to matchmaking.';
  }
}

async function cancelSearch() {
  loading.value = true;
  try {
    await api.post('/pvp/queue/leave');
  } finally {
    loading.value = false;
    stopAllPolling();
    view.value = 'lobby';
  }
}

async function challenge(row) {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post(`/pvp/challenge/${row.character.id}`);
    matchId.value = data.match_id;
    enterLive();
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}

// ---- Live match flow ----

function enterLive() {
  stopAllPolling();
  view.value = 'live';
  loadMatch();
  livePollTimer = setInterval(loadMatch, 2500);
}

async function loadMatch() {
  if (!matchId.value) return;
  try {
    const { data } = await api.get(`/pvp/live/${matchId.value}`);
    match.value = data;
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Could not load the match.';
    stopAllPolling();
    view.value = 'lobby';
    await load();
  }
}

async function act(type, skillId = null) {
  if (!match.value || loading.value || !match.value.is_my_turn) return;
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post(`/pvp/live/${matchId.value}/action`, { type, skill_id: skillId });
    match.value = data;
    if (data.status !== 'active') {
      clearInterval(livePollTimer);
      livePollTimer = null;
    }
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Action failed.';
  } finally {
    loading.value = false;
  }
}

async function forfeit() {
  if (!match.value || loading.value) return;
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post(`/pvp/live/${matchId.value}/forfeit`);
    match.value = data;
    clearInterval(livePollTimer);
    livePollTimer = null;
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Could not forfeit.';
  } finally {
    loading.value = false;
  }
}

async function backToLobby() {
  stopAllPolling();
  view.value = 'lobby';
  match.value = null;
  matchId.value = null;
  await load();
}

const hpPct = (hp, max) => (max > 0 ? Math.max(0, Math.min(100, Math.round((hp / max) * 100))) : 0);

// Clamped/floored defensively — elapsedSeconds should only ever be a non-negative integer, but a stale
// poll landing after cancelSearch()/a clock hiccup previously let a negative or fractional value slip
// through and render as garbage like "-1:-10.86...". This can never display anything but a clean mm:ss.
const searchDuration = computed(() => {
  const total = Math.max(0, Math.floor(elapsedSeconds.value || 0));
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
});

const matchFinished = computed(() => match.value && match.value.status !== 'active');
const fullLog = computed(() => [...(match.value?.log ?? [])].reverse());

/** Turn number this fighter would be acting on if they act right now — cooldowns in state_json are
 * stored as the absolute turn number a skill becomes ready again (see PvpLiveCombatService::resolveTurn). */
function skillReady(skill, fighter) {
  return skill.ready_at_turn <= (fighter?.turns_taken ?? 0) + 1;
}
function skillCooldownTurns(skill, fighter) {
  return Math.max(0, skill.ready_at_turn - ((fighter?.turns_taken ?? 0) + 1));
}

onMounted(load);
onUnmounted(stopAllPolling);
</script>

<template>
  <div>
    <div class="pvp-header">
      <div class="pvp-header__icon">⚔</div>
      <h1 class="ox pvp-title">PvP Arena</h1>
    </div>

    <div v-if="errorMessage" class="pvp-error-banner">{{ errorMessage }}</div>

    <!-- ============ LOBBY ============ -->
    <div v-if="view === 'lobby'" class="pvp-layout">
      <div class="pvp-main">
        <div v-if="record" class="rank-card">
          <div class="rank-card__top">
            <div
              class="rank-card__icon"
              :style="rank ? { background: rank.color + '29', borderColor: rank.color + '55', boxShadow: '0 0 22px ' + rank.color + '3d' } : {}"
            >{{ rank?.glyph || '⚔' }}</div>
            <div class="rank-card__info">
              <div class="ox rank-card__rank" :style="rank ? { color: rank.color } : {}">{{ rank?.name }}</div>
              <div class="rank-card__meta">
                {{ record.rating }} rating · {{ rank?.percentile_label }} · {{ record.wins }}W / {{ record.losses }}L ·
                {{ (record.wins + record.losses) > 0 ? Math.round((record.wins / (record.wins + record.losses)) * 100) : 0 }}% winrate
              </div>
              <div v-if="record.win_streak > 0" class="rank-card__streak">🔥 {{ record.win_streak }} win streak</div>
            </div>
            <div class="rank-card__attempts-wrap">
              <button @click="findMatch" :disabled="loading" class="btn-find-match">
                Find match
              </button>
              <div class="rank-card__attempts">Queues you against a live opponent near your rating</div>
            </div>
          </div>

          <div v-if="rankProgress" class="tier-progress">
            <div class="tier-progress__track">
              <div
                class="tier-progress__fill"
                :style="{ width: rankProgress.pct + '%', background: 'linear-gradient(90deg, ' + (rank?.color || '#e8482f') + ', #ffffff)' }"
              ></div>
            </div>
            <div class="tier-progress__label">
              <span v-if="rankProgress.next">{{ rankProgress.pct }}% to {{ rankProgress.next.name }}</span>
              <span v-else>Highest rank reached</span>
            </div>
            <div class="tier-ladder">
              <span
                v-for="t in rankLadder"
                :key="t.name"
                class="tier-ladder__pill"
                :class="{ 'tier-ladder__pill--current': t.is_current }"
                :style="t.is_current ? { color: t.color, borderColor: t.color, background: t.color + '22' } : { borderColor: t.color + '55' }"
              >
                {{ t.name }}
              </span>
            </div>
          </div>
        </div>

        <div class="challenge-eyebrow">CHALLENGE A RIVAL</div>
        <div class="opponents-grid">
          <div v-for="row in opponents" :key="row.character.id" class="opponent-card">
            <div class="opponent-card__top">
              <div class="opponent-card__info">
                <router-link :to="{ name: 'public-profile', params: { id: row.character.id } }" class="opponent-card__name opponent-card__name--link">{{ row.character.name }}</router-link>
                <div class="opponent-card__meta">{{ row.character.base_class }} · Lv.{{ row.character.level }}</div>
              </div>
              <span
                v-if="row.difficulty"
                class="opponent-card__difficulty"
                :style="{ color: difficultyColor[row.difficulty], background: difficultyColor[row.difficulty] + '1f' }"
              >{{ row.difficulty }}</span>
            </div>
            <div class="opponent-card__bottom">
              <span class="opponent-card__rating">{{ row.rating }} rating <span class="opponent-card__bracket" :style="{ color: row.rank?.color }">· {{ row.rank?.name }}</span></span>
              <button @click="challenge(row)" :disabled="loading" class="btn-challenge">
                Challenge
              </button>
            </div>
          </div>
          <div v-if="!opponents.length" class="opponents-empty">No other players yet.</div>
        </div>
      </div>

      <div class="pvp-sidebar">
        <div class="history-eyebrow">MATCH HISTORY</div>
        <div class="history-card">
          <div v-for="h in history" :key="h.id" class="history-row">
            <div>
              <div class="history-row__name">vs {{ h.opponent.name }}</div>
              <div
                class="history-row__result"
                :class="h.result === 'win' ? 'history-row__result--win' : 'history-row__result--loss'"
              >
                {{ h.result === 'win' ? 'Victory' : 'Defeat' }}
              </div>
            </div>
            <span
              class="ox history-row__delta"
              :class="h.result === 'win' ? 'history-row__delta--win' : 'history-row__delta--loss'"
            >{{ h.rating_delta >= 0 ? '+' : '' }}{{ h.rating_delta }}</span>
          </div>
          <div v-if="!history.length" class="history-empty">No matches yet.</div>
        </div>

        <div class="season-reward-card">
          <div class="season-reward-card__title">🏆 Season reward</div>
          <div class="season-reward-card__body">Reach Diamond for the Gladiator title & exclusive rewards.</div>
        </div>
      </div>
    </div>

    <!-- ============ SEARCHING ============ -->
    <div v-else-if="view === 'searching'" class="searching-view">
      <div class="searching-view__art">⚔</div>
      <div class="ox searching-view__title">Searching for an opponent…</div>
      <div class="searching-view__timer">{{ searchDuration }}</div>
      <p class="searching-view__hint">Widening the search the longer you wait.</p>
      <button class="btn-cancel-search" @click="cancelSearch" :disabled="loading">Cancel</button>
    </div>

    <!-- ============ LIVE MATCH ============ -->
    <div v-else-if="view === 'live' && match" class="live-match">
      <div v-if="!matchFinished" class="turn-banner" :class="{ 'turn-banner--mine': match.is_my_turn }">
        {{ match.is_my_turn ? "🟢 Your turn!" : `⏳ Waiting for ${match.opponent?.name}…` }}
      </div>

      <div class="fighters-row">
        <div class="fighter-card" :class="{ 'fighter-card--turn': match.turn_character_id === match.me?.character_id }">
          <div class="ox fighter-card__name">{{ match.me?.name }} (You)</div>
          <div class="stat-block">
            <div class="stat-label-row"><span class="hp">HP</span><span>{{ match.me?.hp }} / {{ match.me?.hp_max }}</span></div>
            <div class="stat-bar-track"><div class="stat-bar-fill--hp" :style="{ width: hpPct(match.me?.hp, match.me?.hp_max) + '%' }"></div></div>
          </div>
          <div class="stat-block">
            <div class="stat-label-row"><span class="mp">MP</span><span>{{ match.me?.mana }} / {{ match.me?.mana_max }}</span></div>
            <div class="stat-bar-track"><div class="stat-bar-fill--mp" :style="{ width: hpPct(match.me?.mana, match.me?.mana_max) + '%' }"></div></div>
          </div>
        </div>

        <div class="fighters-row__vs">VS</div>

        <div class="fighter-card" :class="{ 'fighter-card--turn': match.turn_character_id === match.opponent?.character_id }">
          <div class="ox fighter-card__name">{{ match.opponent?.name }}</div>
          <div class="stat-block">
            <div class="stat-label-row"><span class="hp">HP</span><span>{{ match.opponent?.hp }} / {{ match.opponent?.hp_max }}</span></div>
            <div class="stat-bar-track"><div class="stat-bar-fill--hp" :style="{ width: hpPct(match.opponent?.hp, match.opponent?.hp_max) + '%' }"></div></div>
          </div>
          <div class="stat-block">
            <div class="stat-label-row"><span class="mp">MP</span><span>{{ match.opponent?.mana }} / {{ match.opponent?.mana_max }}</span></div>
            <div class="stat-bar-track"><div class="stat-bar-fill--mp" :style="{ width: hpPct(match.opponent?.mana, match.opponent?.mana_max) + '%' }"></div></div>
          </div>
        </div>
      </div>

      <!-- Actions: only shown/enabled on my turn -->
      <div v-if="!matchFinished" class="actions-grid">
        <button class="btn-attack" @click="act('attack')" :disabled="loading || !match.is_my_turn">⚔ Attack</button>
        <button
          v-for="skill in match.me?.skills ?? []"
          :key="skill.skill_id"
          class="btn-skill"
          @click="act('skill', skill.skill_id)"
          :disabled="loading || !match.is_my_turn || match.me.mana < skill.mp_cost || !skillReady(skill, match.me)"
        >
          <template v-if="!skillReady(skill, match.me)">
            {{ skill.glyph }} {{ skill.name }} — {{ skillCooldownTurns(skill, match.me) }} turn{{ skillCooldownTurns(skill, match.me) > 1 ? 's' : '' }}
          </template>
          <template v-else>
            {{ skill.glyph }} {{ skill.name }} ({{ skill.mp_cost }} MP)
          </template>
        </button>
        <button class="flee-btn" @click="forfeit" :disabled="loading">Forfeit</button>
      </div>

      <!-- Finished banner -->
      <div v-else class="result-view">
        <div class="result-icon">{{ match.i_won ? '🏆' : '💀' }}</div>
        <div class="ox result-title" :style="{ color: match.i_won ? '#4ade80' : '#ff6a4d' }">
          {{ match.i_won ? 'Victory!' : match.status === 'forfeited' ? 'Match forfeited' : 'Defeated' }}
        </div>

        <div v-if="match.reward" class="reward-chips">
          <div class="reward-chip--gold" v-if="match.i_won">{{ match.reward.rating_delta >= 0 ? '+' : '' }}{{ match.reward.rating_delta }} rating</div>
          <div class="reward-chip--loss" v-else>{{ -match.reward.rating_delta >= 0 ? '+' : '' }}{{ -match.reward.rating_delta }} rating</div>
        </div>

        <div v-if="match.i_won && match.reward?.daily_reward_granted" class="daily-reward-banner">
          <span class="daily-reward-banner__icon">🎁</span>
          <span class="daily-reward-banner__text">
            <strong>First win of the day!</strong>
            +{{ match.reward.daily_reward_gold }} gold, +{{ match.reward.daily_reward_gems }} gems added to your balance.
          </span>
        </div>
        <div v-if="match.i_won && match.reward?.ten_win_reward_granted" class="daily-reward-banner daily-reward-banner--ten-wins">
          <span class="daily-reward-banner__icon">🏅</span>
          <span class="daily-reward-banner__text">
            <strong>10 wins today!</strong>
            +{{ match.reward.ten_win_reward_gold }} gold, +{{ match.reward.ten_win_reward_gems }} gems bonus added.
          </span>
        </div>

        <div class="result-actions">
          <button class="btn-dashboard-link" @click="backToLobby">Back to Arena</button>
        </div>
      </div>

      <div class="battle-log">
        <div v-for="(line, i) in fullLog" :key="i" class="battle-log__line">{{ line }}</div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./PvpPage.scss" scoped></style>
