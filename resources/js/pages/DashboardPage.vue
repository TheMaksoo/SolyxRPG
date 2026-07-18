<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';

const store = useCharacterStore();
const daily = ref(null);
const battlePass = ref(null);
const leaders = ref([]);
const quests = ref([]);
const announcements = ref([]);

async function loadRail() {
  const [dailyRes, passRes, lbRes, questRes, annRes] = await Promise.all([
    api.get('/daily'),
    api.get('/battlepass'),
    api.get('/leaderboard'),
    api.get('/quests'),
    api.get('/announcements'),
  ]);
  daily.value = dailyRes.data;
  battlePass.value = passRes.data.battle_pass;
  leaders.value = lbRes.data.leaderboard.slice(0, 5);
  quests.value = questRes.data.quests.filter((q) => !q.completed).slice(0, 3);
  announcements.value = annRes.data.announcements.slice(0, 3);
}

async function claimDaily() {
  await api.post('/daily/claim');
  const { data } = await api.get('/daily');
  daily.value = data;
}

const xpPct = computed(() => {
  const xpMax = store.stats?.xp_max || 1;
  return Math.min(100, Math.round(((store.character?.xp ?? 0) / xpMax) * 100));
});

onMounted(() => {
  store.fetch();
  loadRail();
});
</script>

<template>
  <div v-if="store.loading && !store.character" class="dashboard-loading">Loading…</div>

  <div v-else-if="store.character" class="dashboard">
    <div class="dashboard__main">
      <!-- Daily reward banner -->
      <div v-if="daily" class="daily-banner">
        <div class="daily-banner__info">
          <div class="daily-banner__icon">🎁</div>
          <div>
            <div class="daily-banner__title">Daily reward — Day {{ daily.streak }} streak</div>
            <div class="daily-banner__subtitle">Log in every day to keep your streak going</div>
          </div>
        </div>
        <button @click="claimDaily" :disabled="!daily.can_claim" class="daily-banner__claim">
          {{ daily.can_claim ? 'Claim' : 'Claimed' }}
        </button>
      </div>

      <!-- Zone card -->
      <router-link to="/world-map" class="zone-card">
        <div class="zone-card__art">
          {{ store.character.zone?.glyph ?? '🗺' }}
        </div>
        <div class="zone-card__body">
          <div class="ox zone-card__name">{{ store.character.zone?.name ?? 'No zone selected' }}</div>
          <div class="zone-card__desc">
            {{ store.character.zone ? `Danger: ${store.character.zone.danger}` : 'Pick a zone in World Map to start fighting' }}
          </div>
          <span v-if="store.character.zone" class="zone-card__badge"
            >Recommended Lv.{{ store.character.zone.min_level }}+</span
          >
        </div>
      </router-link>

      <!-- Stat tiles -->
      <div class="stat-tiles">
        <div class="stat-tile">
          <div class="xp-gauge" :style="{ '--xp-pct': xpPct }">
            <div class="xp-gauge__inner">
              <div class="ox xp-gauge__level">{{ store.character.level }}</div>
              <div class="xp-gauge__tag">LVL</div>
            </div>
          </div>
          <div class="stat-tile__label">{{ store.character.xp }} / {{ store.stats?.xp_max ?? '—' }} XP</div>
        </div>
        <div class="stat-tile">
          <div class="ox stat-tile__value stat-tile__value--gold">{{ store.character.gold }}</div>
          <div class="stat-tile__label">Gold</div>
        </div>
        <div class="stat-tile">
          <div class="ox stat-tile__value stat-tile__value--hp">{{ store.character.hp }}/{{ store.stats?.eff_hp_max ?? store.character.hp_max }}</div>
          <div class="stat-tile__label">HP</div>
          <div v-if="!store.inCombat && store.character.hp < (store.stats?.eff_hp_max ?? store.character.hp_max)" class="stat-tile__regen">
            💚 healing +{{ store.regenPerTick }}/5s
          </div>
        </div>
        <div class="stat-tile">
          <div class="ox stat-tile__value stat-tile__value--mana">{{ store.character.mana }}/{{ store.stats?.eff_mp_max ?? store.character.mana_max }}</div>
          <div class="stat-tile__label">Mana</div>
          <div v-if="!store.inCombat && store.character.mana < (store.stats?.eff_mp_max ?? store.character.mana_max)" class="stat-tile__regen">
            💧 regen +{{ store.manaRegenPerTick }}/5s
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="quick-actions">
        <router-link to="/battle" class="quick-actions__primary">⚔ Fight Monster</router-link>
        <router-link to="/quests" class="quick-actions__secondary">❖ Quests</router-link>
        <router-link to="/shop" class="quick-actions__secondary">◉ Shop</router-link>
      </div>

      <!-- Equipped -->
      <div class="equipped-eyebrow">EQUIPPED</div>
      <div class="equipped-grid">
        <div
          v-for="row in (store.character.inventory || []).filter((i) => i.equipped)"
          :key="row.id"
          class="equipped-item"
        >
          <div class="equipped-item__name">{{ row.item.glyph }} {{ row.item.name }}</div>
          <div class="equipped-item__type">{{ row.item.type }}</div>
        </div>
        <div
          v-if="!(store.character.inventory || []).some((i) => i.equipped)"
          class="equipped-empty"
        >
          Nothing equipped — visit the <router-link to="/inventory" class="equipped-empty__link">Inventory</router-link>.
        </div>
      </div>

      <AdBanner variant="inline" />
    </div>

    <!-- Right rail -->
    <div class="dashboard__rail">
      <div v-if="battlePass" class="battlepass-card">
        <div class="battlepass-card__header">
          <div class="ox battlepass-card__title">Ashfall Pass</div>
          <span class="battlepass-card__tier">Tier {{ battlePass.tier }}</span>
        </div>
        <router-link to="/battle-pass" class="battlepass-card__cta">View Battle Pass</router-link>
      </div>

      <div class="rail-card">
        <div class="rail-eyebrow">LEADERBOARD</div>
        <div v-for="l in leaders" :key="l.character_id" class="leaderboard-row">
          <div class="leaderboard-row__left">
            <span class="ox leaderboard-row__rank">{{ l.rank }}</span>
            <span class="leaderboard-row__name">{{ l.name }}</span>
          </div>
          <span class="leaderboard-row__level">Lv.{{ l.level }}</span>
        </div>
        <div v-if="!leaders.length" class="rail-empty">No ranked characters yet.</div>
      </div>

      <div class="rail-card">
        <div class="rail-eyebrow">ACTIVE QUESTS</div>
        <div v-for="row in quests" :key="row.quest.id" class="quest-item">
          <div class="quest-item__name">{{ row.quest.name }}</div>
          <div class="quest-item__track">
            <div
              class="quest-item__fill"
              :style="{ width: Math.min(100, Math.round((row.progress / (row.quest.goal_json.target ?? 1)) * 100)) + '%' }"
            ></div>
          </div>
          <div class="quest-item__progress">{{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}</div>
        </div>
        <div v-if="!quests.length" class="rail-empty">No active quests.</div>
      </div>

      <div class="rail-card">
        <div class="rail-eyebrow">ANNOUNCEMENTS</div>
        <div v-for="an in announcements" :key="an.id" class="announcement-row">
          <div class="announcement-row__icon">📣</div>
          <div class="announcement-row__content">
            <div class="announcement-row__text">{{ an.body }}</div>
            <div class="announcement-row__author">{{ an.gm?.name }}</div>
          </div>
        </div>
        <div v-if="!announcements.length" class="rail-empty">No announcements yet.</div>
      </div>

      <WorldChat />
    </div>
  </div>
</template>

<style lang="scss" src="./DashboardPage.scss" scoped></style>
