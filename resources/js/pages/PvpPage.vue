<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const record = ref(null);
const rank = ref('');
const opponents = ref([]);
const history = ref([]);
const lastResult = ref(null);
const loading = ref(false);

async function load() {
  const { data } = await api.get('/pvp');
  record.value = data.record;
  rank.value = data.rank;
  opponents.value = data.opponents;
  history.value = data.history;
}

async function findMatch() {
  loading.value = true;
  try {
    const { data } = await api.post('/pvp/find-match');
    lastResult.value = data;
    await load();
  } finally {
    loading.value = false;
  }
}

async function challenge(row) {
  loading.value = true;
  try {
    const { data } = await api.post(`/pvp/challenge/${row.character.id}`);
    lastResult.value = data;
    await load();
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

    <div class="pvp-layout">
      <div class="pvp-main">
        <div v-if="record" class="rank-card">
          <div class="rank-card__icon">⚔</div>
          <div class="rank-card__info">
            <div class="ox rank-card__rank">{{ rank }}</div>
            <div class="rank-card__meta">{{ record.rating }} rating · {{ record.wins }}W / {{ record.losses }}L</div>
            <div v-if="record.win_streak > 0" class="rank-card__streak">🔥 {{ record.win_streak }} win streak</div>
          </div>
          <button
            @click="findMatch"
            :disabled="loading"
            class="btn-find-match"
          >
            Find ranked match
          </button>
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
            </div>
            <div class="opponent-card__bottom">
              <span class="opponent-card__rating">{{ row.rating }} rating <span class="opponent-card__bracket">· {{ row.bracket }}</span></span>
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
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./PvpPage.scss" scoped></style>
