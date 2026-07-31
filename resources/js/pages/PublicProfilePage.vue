<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api/client';
import VipBadge from '../components/VipBadge.vue';
import { RARITY_COLORS, RARITY_LABELS } from '../rarity';

const route = useRoute();
const router = useRouter();
const profile = ref(null);
const power = ref(0);
const achievementsEarned = ref(0);
const achievementsTotal = ref(0);
const loading = ref(true);
const notFound = ref(false);
const equippedGear = ref([]);
const guildCard = ref(null);
const rankings = ref([]);
const headToHead = ref(null);
const privacy = ref(null);

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
    equippedGear.value = data.equipped_gear;
    gearScore.value = data.gear_score;
    guildCard.value = data.guild_card;
    rankings.value = data.rankings;
    headToHead.value = data.head_to_head;
    privacy.value = data.privacy;
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
const frameColor = computed(() => profile.value?.active_frame?.value || null);

const battlesTotal = computed(() => (profile.value?.battles_won ?? 0) + (profile.value?.battles_lost ?? 0));
const winRate = computed(() => (battlesTotal.value > 0 ? Math.round(((profile.value?.battles_won ?? 0) / battlesTotal.value) * 100) : 0));

const memberSince = computed(() => {
  if (!profile.value?.created_at) return null;
  return new Date(profile.value.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long' });
});

const gearScore = ref(0);

const playtimeLabel = computed(() => {
  const seconds = profile.value?.playtime_seconds;
  if (seconds == null) return null;
  const hours = Math.floor(seconds / 3600);
  return hours < 48 ? `${hours}h` : `${Math.floor(hours / 24)}d ${hours % 24}h`;
});
</script>

<template>
  <div>
    <button class="public-profile-back" @click="router.back()">← Back</button>

    <div v-if="loading" class="public-profile-loading">Loading…</div>
    <div v-else-if="notFound" class="public-profile-empty">Character not found.</div>

    <template v-else-if="profile">
      <div class="public-profile-hero" :style="heroBackground ? { background: heroBackground } : null">
        <div v-if="profile.active_banner || profile.active_frame" class="public-profile-hero__cosmetic-tags">
          <span v-if="profile.active_banner" class="public-profile-hero__cosmetic-tag" :title="profile.active_banner.name">
            🎌 {{ profile.active_banner.name }}
          </span>
          <span v-if="profile.active_frame" class="public-profile-hero__cosmetic-tag" :title="profile.active_frame.name">
            🖼 {{ profile.active_frame.name }}
          </span>
        </div>
        <div class="public-profile-hero__avatar-wrap">
          <div
            class="ox public-profile-hero__avatar"
            :class="{ 'public-profile-hero__avatar--icon': iconGlyph }"
            :style="frameColor ? { borderColor: frameColor, boxShadow: `0 0 22px ${frameColor}44` } : null"
            :title="iconGlyph ? profile.active_icon?.name : null"
          >
            {{ iconGlyph || profile.name.slice(0, 2).toUpperCase() }}
          </div>
          <div class="ox public-profile-hero__level-badge">LV {{ profile.level }}</div>
        </div>
        <div class="public-profile-hero__info">
          <div class="public-profile-hero__name-row">
            <div class="ox public-profile-hero__name" :style="nameColor ? { color: nameColor } : null">{{ profile.name }}</div>
            <div v-if="titleText" class="public-profile-hero__title-badge">{{ titleText }}</div>
            <VipBadge :tier="profile.vip_tier" />
            <div class="public-profile-hero__online" :class="{ 'public-profile-hero__online--live': profile.is_online }">
              <span class="public-profile-hero__online-dot"></span>{{ profile.is_online ? 'Online' : 'Offline' }}<span v-if="profile.zone_name"> · {{ profile.zone_name }}</span>
            </div>
          </div>
          <div class="public-profile-hero__class">
            {{ profile.spec_class || profile.base_class }} · Level {{ profile.level }}
            <span v-if="profile.guild" class="public-profile-hero__guild">· [{{ profile.guild.tag }}] {{ profile.guild.name }}</span>
          </div>
          <div class="public-profile-hero__meta">
            {{ profile.battles_won }} won · {{ profile.battles_lost }} lost ({{ winRate }}% win rate) · {{ profile.bosses_slain }} bosses slain
          </div>
          <div v-if="profile.bio" class="public-profile-hero__bio">{{ profile.bio }}</div>
          <div v-if="(profile.playstyle_tags || []).length" class="public-profile-hero__tags">
            <span v-for="t in profile.playstyle_tags" :key="t" class="public-profile-hero__tag">{{ t }}</span>
          </div>
          <div v-if="memberSince" class="public-profile-hero__since">Adventuring since {{ memberSince }}</div>
        </div>
        <div class="public-profile-hero__stats">
          <div v-if="power !== null" class="public-profile-stat">
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

      <div class="public-profile-columns">
        <div class="public-profile-columns__main">
          <div class="public-profile-panel">
            <div class="public-profile-panel__head">
              <span class="public-profile-panel__eyebrow">EQUIPPED LOADOUT</span>
              <span v-if="equippedGear.length" class="public-profile-panel__head-note">Gear score <b class="ox">{{ gearScore }}</b></span>
            </div>
            <div v-if="equippedGear.length" class="public-profile-loadout-grid">
              <div
                v-for="g in equippedGear"
                :key="g.name"
                class="public-profile-loadout-item"
                :style="{ borderColor: `${RARITY_COLORS[g.rarity]}55` }"
              >
                <div class="public-profile-loadout-item__icon" :style="{ background: `${RARITY_COLORS[g.rarity]}1a`, borderColor: `${RARITY_COLORS[g.rarity]}55` }">{{ g.glyph }}</div>
                <div class="public-profile-loadout-item__info">
                  <div class="public-profile-loadout-item__name" :style="{ color: RARITY_COLORS[g.rarity] }">{{ g.name }}</div>
                  <div class="public-profile-loadout-item__meta">{{ g.slot }} · {{ RARITY_LABELS[g.rarity] }}</div>
                </div>
                <div class="ox public-profile-loadout-item__score">{{ g.score }}</div>
              </div>
            </div>
            <p v-else-if="privacy && !privacy.gear_visible" class="public-profile-panel__empty">This player has hidden their loadout.</p>
            <p v-else class="public-profile-panel__empty">Nothing equipped.</p>
          </div>
        </div>

        <div class="public-profile-columns__rail">
          <div v-if="headToHead" class="public-profile-panel public-profile-panel--accent">
            <div class="public-profile-panel__eyebrow">HEAD TO HEAD</div>
            <div class="public-profile-h2h">
              <div class="public-profile-h2h__side">
                <div class="ox public-profile-h2h__value public-profile-h2h__value--them">{{ headToHead.their_wins }}</div>
                <div class="public-profile-h2h__label">their wins</div>
              </div>
              <div class="public-profile-h2h__dash">—</div>
              <div class="public-profile-h2h__side">
                <div class="ox public-profile-h2h__value public-profile-h2h__value--you">{{ headToHead.your_wins }}</div>
                <div class="public-profile-h2h__label">your wins</div>
              </div>
            </div>
            <div class="public-profile-h2h__note">
              Last duel: {{ headToHead.last_result === 'win' ? 'you won' : 'they won' }} — {{ new Date(headToHead.last_played_at).toLocaleDateString() }}
            </div>
            <router-link to="/pvp" class="public-profile-h2h__queue-btn">Queue for a duel →</router-link>
          </div>

          <div class="public-profile-panel">
            <div class="public-profile-panel__eyebrow">RANKINGS</div>
            <div v-for="r in rankings" :key="r.category" class="public-profile-ranking-row">
              <span class="public-profile-ranking-row__label">{{ r.label }}</span>
              <span class="ox public-profile-ranking-row__rank">{{ r.rank ? `#${r.rank}` : '—' }}</span>
            </div>
          </div>

          <div v-if="guildCard" class="public-profile-panel">
            <div class="public-profile-panel__eyebrow">GUILD</div>
            <div class="public-profile-guild">
              <div class="ox public-profile-guild__tag">{{ guildCard.tag }}</div>
              <div class="public-profile-guild__info">
                <div class="public-profile-guild__name">{{ guildCard.name }}</div>
                <div class="public-profile-guild__meta">{{ guildCard.role }} · {{ guildCard.member_count }} members · Lv {{ guildCard.level }}</div>
              </div>
            </div>
          </div>

          <div v-if="privacy" class="public-profile-panel">
            <div class="public-profile-panel__eyebrow">PLAYER INFO</div>
            <div class="public-profile-info-row">
              <span class="public-profile-info-row__label">Last seen</span>
              <span class="public-profile-info-row__value" :class="{ 'public-profile-info-row__value--live': profile.is_online }">{{ profile.is_online ? 'Online' : 'Offline' }}</span>
            </div>
            <div class="public-profile-info-row">
              <span class="public-profile-info-row__label">Loadout & gear score</span>
              <span class="public-profile-info-row__value" :class="{ 'public-profile-info-row__value--live': privacy.gear_visible }">{{ privacy.gear_visible ? 'Public' : 'Hidden' }}</span>
            </div>
            <div class="public-profile-info-row">
              <span class="public-profile-info-row__label">Combat stats</span>
              <span class="public-profile-info-row__value" :class="{ 'public-profile-info-row__value--live': privacy.combat_stats_visible }">{{ privacy.combat_stats_visible ? 'Public' : 'Hidden' }}</span>
            </div>
            <div v-if="playtimeLabel" class="public-profile-info-row">
              <span class="public-profile-info-row__label">Playtime</span>
              <span class="public-profile-info-row__value public-profile-info-row__value--live">{{ playtimeLabel }}</span>
            </div>
            <div class="public-profile-info-row">
              <span class="public-profile-info-row__label">Duels</span>
              <span class="public-profile-info-row__value" :class="{ 'public-profile-info-row__value--live': privacy.open_to_duels }">{{ privacy.open_to_duels ? 'Open' : 'Closed' }}</span>
            </div>
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
