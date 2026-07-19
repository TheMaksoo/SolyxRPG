<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import VipBadge from '../components/VipBadge.vue';

const route = useRoute();
const router = useRouter();
const profile = ref(null);
const power = ref(0);
const achievementsEarned = ref(0);
const achievementsTotal = ref(0);
const loading = ref(true);
const notFound = ref(false);

const FUN_STAT_ROWS = [
  { key: 'times_mined', label: 'Ore Mined', glyph: '⛏' },
  { key: 'times_chopped', label: 'Trees Chopped', glyph: '🪓' },
  { key: 'times_smelted', label: 'Bars Smelted', glyph: '🔥' },
  { key: 'times_foraged', label: 'Herbs Foraged', glyph: '🌿' },
  { key: 'times_crafted', label: 'Items Crafted', glyph: '🔨' },
  { key: 'battles_lost', label: 'Times Died', glyph: '💀' },
];

async function load() {
  loading.value = true;
  notFound.value = false;
  try {
    const { data } = await api.get(`/characters/${route.params.id}/profile`);
    profile.value = data.character;
    power.value = data.power;
    achievementsEarned.value = data.achievements_earned;
    achievementsTotal.value = data.achievements_total;
  } catch (e) {
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => route.params.id, load);
onMounted(load);

const heroBackground = computed(() => profile.value?.active_banner?.value || null);
const nameColor = computed(() => profile.value?.active_color?.value || null);
const titleText = computed(() => profile.value?.active_title?.value || null);
const iconGlyph = computed(() => profile.value?.active_icon?.value || null);

const battlesTotal = computed(() => (profile.value?.battles_won ?? 0) + (profile.value?.battles_lost ?? 0));
const winRate = computed(() => (battlesTotal.value > 0 ? Math.round(((profile.value?.battles_won ?? 0) / battlesTotal.value) * 100) : 0));

const memberSince = computed(() => {
  if (!profile.value?.created_at) return null;
  return new Date(profile.value.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long' });
});
</script>

<template>
  <div>
    <button class="public-profile-back" @click="router.back()">← Back</button>

    <div v-if="loading" class="public-profile-loading">Loading…</div>
    <div v-else-if="notFound" class="public-profile-empty">Character not found.</div>

    <template v-else-if="profile">
      <div class="public-profile-hero" :style="heroBackground ? { background: heroBackground } : null">
        <div class="ox public-profile-hero__avatar" :class="{ 'public-profile-hero__avatar--icon': iconGlyph }">
          {{ iconGlyph || profile.name.slice(0, 2).toUpperCase() }}
        </div>
        <div class="public-profile-hero__info">
          <div class="public-profile-hero__name-row">
            <div class="ox public-profile-hero__name" :style="nameColor ? { color: nameColor } : null">{{ profile.name }}</div>
            <div v-if="titleText" class="public-profile-hero__title-badge">{{ titleText }}</div>
            <VipBadge :tier="profile.vip_tier" />
          </div>
          <div class="public-profile-hero__class">
            {{ profile.spec_class || profile.base_class }} · Level {{ profile.level }}
            <span v-if="profile.guild" class="public-profile-hero__guild">· [{{ profile.guild.tag }}] {{ profile.guild.name }}</span>
          </div>
          <div class="public-profile-hero__meta">
            {{ profile.battles_won }} won · {{ profile.battles_lost }} lost ({{ winRate }}% win rate) · {{ profile.bosses_slain }} bosses slain
          </div>
          <div v-if="memberSince" class="public-profile-hero__since">Adventuring since {{ memberSince }}</div>
        </div>
        <div class="public-profile-hero__stats">
          <div class="public-profile-stat">
            <div class="ox public-profile-stat__value public-profile-stat__value--power">{{ power }}</div>
            <div class="public-profile-stat__label">Power</div>
          </div>
          <div class="public-profile-stat">
            <div class="ox public-profile-stat__value">{{ achievementsEarned }}/{{ achievementsTotal }}</div>
            <div class="public-profile-stat__label">Achievements</div>
          </div>
          <div v-if="profile.pvp_rank" class="public-profile-stat">
            <div class="ox public-profile-stat__value">{{ profile.pvp_rank }}</div>
            <div class="public-profile-stat__label">PvP Rank ({{ profile.pvp_rating }})</div>
          </div>
          <div class="public-profile-stat">
            <div class="ox public-profile-stat__value">{{ profile.quests_completed }}</div>
            <div class="public-profile-stat__label">Quests Done</div>
          </div>
        </div>
      </div>

      <div class="public-profile-fun-eyebrow">FUN STATS</div>
      <div class="public-profile-fun-grid">
        <div v-for="s in FUN_STAT_ROWS" :key="s.key" class="public-profile-fun-chip">
          <div class="public-profile-fun-chip__glyph">{{ s.glyph }}</div>
          <div class="public-profile-fun-chip__value">{{ profile[s.key] ?? 0 }}</div>
          <div class="public-profile-fun-chip__label">{{ s.label }}</div>
        </div>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./PublicProfilePage.scss" scoped></style>
