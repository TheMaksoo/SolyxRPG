<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import AdBanner from '../components/AdBanner.vue';

const characterStore = useCharacterStore();

const record = ref(null);
const rank = ref(null);
const rankProgress = ref(null);
const rankLadder = ref([]);
const opponents = ref([]);
const history = ref([]);
const lastResult = ref(null);
const loading = ref(false);
const attemptsUsed = ref(0);
const attemptsMax = ref(10);
const errorMessage = ref('');

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
  attemptsUsed.value = data.pvp_attempts_used;
  attemptsMax.value = data.pvp_attempts_max;
}

// Both match actions can grant gold/gems (the daily win reward) — syncing the returned character into the
// shared store immediately updates the gold/gem pills in the top bar (see GameLayout.vue), the same way
// BattlePage.vue does after a PvE action. Previously nothing here touched the store, so a granted reward
// was easy to miss: it showed in the in-page callout below but the top bar kept the stale pre-reward total.
function syncCharacter(data) {
  if (data.character) {
    characterStore.character = data.character;
  }
}

async function findMatch() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post('/pvp/find-match');
    lastResult.value = data;
    syncCharacter(data);
    await load();
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}

async function challenge(row) {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post(`/pvp/challenge/${row.character.id}`);
    lastResult.value = data;
    syncCharacter(data);
    await load();
  } catch (e) {
    errorMessage.value = e?.response?.data?.message || 'Something went wrong.';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="pvp-header">
      <div class="pvp-header__icon">⚔</div>
      <h1 class="ox pvp-title">PvP Arena</h1>
    </div>

    <AdBanner variant="inline" />

    <div class="pvp-layout">
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
              <button
                @click="findMatch"
                :disabled="loading || attemptsUsed >= attemptsMax"
                class="btn-find-match"
              >
                Find ranked match
              </button>
              <div class="rank-card__attempts">{{ attemptsMax - attemptsUsed }} / {{ attemptsMax }} attempts left today</div>
            </div>
          </div>

          <div v-if="errorMessage" class="pvp-error-banner">{{ errorMessage }}</div>

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

        <div
          v-if="lastResult"
          class="last-result-card"
          :class="{ 'last-result-card--win': lastResult.result === 'win', 'last-result-card--loss': lastResult.result !== 'win' }"
        >
          <span
            class="last-result-card__title"
            :class="lastResult.result === 'win' ? 'last-result-card__title--win' : 'last-result-card__title--loss'"
          >
            {{ lastResult.result === 'win' ? 'Victory' : 'Defeat' }} vs {{ lastResult.opponent.name }}
          </span>
          <span class="last-result-card__delta">{{ lastResult.rating_delta >= 0 ? '+' : '' }}{{ lastResult.rating_delta }} rating</span>
          <div v-if="lastResult.daily_reward_granted" class="daily-reward-banner">
            <span class="daily-reward-banner__icon">🎁</span>
            <span class="daily-reward-banner__text">
              <strong>First win of the day!</strong>
              +{{ lastResult.daily_reward_gold }} gold, +{{ lastResult.daily_reward_gems }} gems added to your balance.
            </span>
          </div>
          <div v-if="lastResult.log?.length" class="last-result-card__log">
            <div v-for="(line, i) in lastResult.log" :key="i" class="last-result-card__log-line">{{ line }}</div>
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
              <button
                @click="challenge(row)"
                :disabled="loading"
                class="btn-challenge"
              >
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
          <div
            v-for="h in history"
            :key="h.id"
            class="history-row"
          >
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
  </div>
</template>

<style lang="scss" src="./PvpPage.scss" scoped></style>
