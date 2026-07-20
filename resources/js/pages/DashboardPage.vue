<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';

/** Gear counts as "low durability" once it drops to this fraction of max — worth a repair-pack nudge. */
const LOW_DURABILITY_PCT = 0.2;

const store = useCharacterStore();
const auth = useAuthStore();
const daily = ref(null);
const battlePass = ref(null);
const leaders = ref([]);
const quests = ref([]);
const announcements = ref([]);
const tradeSkills = ref([]);
const craftQueue = ref([]);
const autoBattle = ref(null);
const autoGather = ref(null);
const autoBattleSummary = ref(null);
const autoGatherSummary = ref(null);
const unclaimedQuestCount = ref(0);

async function loadRail() {
  const [dailyRes, passRes, lbRes, questRes, annRes, tradeRes, craftRes, autoBattleRes, autoGatherRes] = await Promise.all([
    api.get('/daily'),
    api.get('/battlepass'),
    api.get('/leaderboard'),
    api.get('/quests'),
    api.get('/announcements'),
    api.get('/trade-skills'),
    api.get('/crafting/queue'),
    api.get('/auto-battle'),
    api.get('/auto-gather'),
  ]);
  daily.value = dailyRes.data;
  battlePass.value = passRes.data.battle_pass;
  leaders.value = lbRes.data.leaderboard.slice(0, 5);
  unclaimedQuestCount.value = questRes.data.quests.filter((q) => q.completed && !q.claimed).length;
  quests.value = questRes.data.quests.filter((q) => !q.completed).slice(0, 3);
  announcements.value = annRes.data.announcements.slice(0, 3);
  tradeSkills.value = tradeRes.data.trade_skills;
  craftQueue.value = craftRes.data.jobs;
  autoBattle.value = autoBattleRes.data;
  autoGather.value = autoGatherRes.data;
  // The GET endpoints above already lazily tick Auto-Attack/Auto-Gather forward and apply any
  // gains — surfacing their summaries here means gold/xp/items/level-ups land without ever
  // needing to open the Battle or Trade Skills tabs.
  if (autoBattleRes.data.summary) {
    autoBattleSummary.value = autoBattleRes.data.summary;
    store.fetch();
  }
  if (autoGatherRes.data.summary) {
    autoGatherSummary.value = autoGatherRes.data.summary;
    store.fetch();
  }
}

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

const isGathering = computed(() => tradeSkills.value.some((s) => s.cooldown_remaining > 0));
const isCrafting = computed(() => craftQueue.value.length > 0);
const hasReadyCraft = computed(() => craftQueue.value.some((job) => job.is_ready));

/** True if at least one trade skill target is actually doable right now — unlocked, off cooldown, affordable,
 * and (for Smelting) backed by enough ore. Nudging the player to "go gather" is pointless if nothing's doable. */
const hasActionableTradeSkill = computed(() => {
  const energy = store.character?.energy ?? 0;
  return tradeSkills.value.some(
    (skill) =>
      skill.cooldown_remaining === 0 &&
      skill.targets.some((t) => t.unlocked && t.has_input && energy >= t.energy_cost)
  );
});

const showGatherAlert = computed(() => !isGathering.value && !autoGather.value?.active && hasActionableTradeSkill.value);

/** Fully broken gear contributes zero stats while still equipped (see Character::effectiveStats) — this is the
 * most urgent durability state, distinct from merely "running low". */
const brokenGear = computed(() =>
  (store.character?.inventory || []).filter((row) => row.equipped && row.durability_max && row.durability <= 0)
);

const nearBrokenGear = computed(() =>
  (store.character?.inventory || []).filter(
    (row) =>
      row.equipped &&
      row.durability_max &&
      row.durability > 0 &&
      row.durability / row.durability_max <= LOW_DURABILITY_PCT
  )
);

/** Send the player wherever they can actually act: Inventory if they're holding a repair pack to use right
 * now, otherwise Crafting's Repair Packs section so they can make one. */
const repairLinkTarget = computed(() => {
  const hasRepairPack = (store.character?.inventory || []).some(
    (row) => row.item?.type === 'repair_pack' && row.qty > 0
  );
  return hasRepairPack ? '/inventory' : { path: '/crafting', query: { section: 'repair_pack' } };
});

function gearDurabilityStatus(row) {
  if (!row.durability_max) return null;
  if (row.durability <= 0) return 'broken';
  if (row.durability / row.durability_max <= LOW_DURABILITY_PCT) return 'low';
  return null;
}

const hasUnclaimedBattlePass = computed(() => {
  const bp = battlePass.value;
  if (!bp) return false;
  const freeClaimed = bp.claimed_free_tiers || [];
  const premClaimed = bp.claimed_premium_tiers || [];
  for (let tier = 1; tier <= bp.tier; tier++) {
    if (!freeClaimed.includes(tier)) return true;
    if (bp.premium && !premClaimed.includes(tier)) return true;
  }
  return false;
});

const unspentPoints = computed(
  () => (store.character?.attribute_points ?? 0) + (store.character?.skill_points ?? 0)
);

const vipTimeLeft = computed(() => {
  const user = auth.user;
  if (!user || !user.vip_tier || user.vip_tier === 'none' || !user.vip_expires_at) return null;

  const msLeft = new Date(user.vip_expires_at).getTime() - Date.now();
  if (msLeft <= 0) return null;

  return {
    tier: user.vip_tier,
    days: Math.floor(msLeft / 86400000),
    hours: Math.floor((msLeft % 86400000) / 3600000),
  };
});

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
      <div v-if="autoBattleSummary" class="claim-summary">
        <div class="claim-summary__header">
          <span class="ox claim-summary__title">🎉 While you were away — Auto-Attack</span>
          <button class="claim-summary__close" @click="autoBattleSummary = null">✕</button>
        </div>
        <div class="claim-summary__rows">
          <span class="claim-summary__chip">⚔ {{ autoBattleSummary.fights }} fought</span>
          <span class="claim-summary__chip">🏆 {{ autoBattleSummary.wins }} won</span>
          <span v-if="autoBattleSummary.losses" class="claim-summary__chip">💀 {{ autoBattleSummary.losses }} lost</span>
          <span v-if="autoBattleSummary.fled" class="claim-summary__chip">🏃 {{ autoBattleSummary.fled }} fled</span>
          <span class="claim-summary__chip">🪙 +{{ autoBattleSummary.gold }} gold</span>
          <span class="claim-summary__chip">✦ +{{ autoBattleSummary.xp }} xp</span>
          <span v-if="autoBattleSummary.gems" class="claim-summary__chip">💎 +{{ autoBattleSummary.gems }} gems</span>
        </div>
      </div>

      <div v-if="autoGatherSummary" class="claim-summary">
        <div class="claim-summary__header">
          <span class="ox claim-summary__title">🎉 While you were away — Auto-Gather</span>
          <button class="claim-summary__close" @click="autoGatherSummary = null">✕</button>
        </div>
        <div class="claim-summary__rows">
          <span class="claim-summary__chip">{{ autoGatherSummary.actions }} actions</span>
          <span class="claim-summary__chip">+{{ autoGatherSummary.qty }} {{ autoGatherSummary.target_label }}</span>
          <span class="claim-summary__chip">✦ +{{ autoGatherSummary.xp }} xp</span>
          <span v-if="autoGatherSummary.leveled_up" class="claim-summary__chip">⭐ {{ autoGatherSummary.skill_label }} leveled up!</span>
          <span v-if="autoGatherSummary.stopped_reason === 'energy'" class="claim-summary__chip">⚡ ran out of energy</span>
          <span v-if="autoGatherSummary.stopped_reason === 'materials'" class="claim-summary__chip">📦 ran out of materials</span>
        </div>
      </div>

      <!-- Daily reward banner -->
      <div v-if="daily" class="daily-banner">
        <div class="daily-banner__info">
          <div class="daily-banner__icon">🎁</div>
          <div>
            <div class="daily-banner__title">Daily reward — Day {{ daily.streak }} streak</div>
            <div class="daily-banner__subtitle">
              Day {{ daily.cycle_day }} of {{ daily.cycle_length }} this month —
              <router-link to="/daily" class="daily-banner__link">see the full calendar</router-link>
            </div>
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
          <div class="stat-tile__regen">💚 healing +{{ store.regenPerTick }}/5s</div>
        </div>
        <div class="stat-tile">
          <div class="ox stat-tile__value stat-tile__value--mana">{{ store.character.mana }}/{{ store.stats?.eff_mp_max ?? store.character.mana_max }}</div>
          <div class="stat-tile__label">Mana</div>
          <div class="stat-tile__regen">💧 regen +{{ store.manaRegenPerTick }}/5s</div>
        </div>
        <div class="stat-tile">
          <div class="ox stat-tile__value stat-tile__value--energy">{{ store.character.energy }}/{{ store.stats?.eff_energy_max ?? store.character.energy_max }}</div>
          <div class="stat-tile__label">Energy</div>
          <div class="stat-tile__regen">⚡ regen +{{ store.energyRegenPerTick }}/5s</div>
        </div>
      </div>

      <!-- Alerts -->
      <div
        v-if="
          showGatherAlert ||
          !isCrafting ||
          brokenGear.length ||
          nearBrokenGear.length ||
          unspentPoints > 0 ||
          (autoBattle && autoBattle.active) ||
          unclaimedQuestCount > 0 ||
          (daily && daily.can_claim) ||
          hasUnclaimedBattlePass
        "
        class="dashboard-alerts"
      >
        <router-link v-if="daily && daily.can_claim" to="/daily" class="dashboard-alert dashboard-alert--reward">
          🎁 Your daily reward is ready to claim.
        </router-link>
        <router-link v-if="unclaimedQuestCount > 0" to="/quests" class="dashboard-alert dashboard-alert--reward">
          ❖ {{ unclaimedQuestCount }} completed quest{{ unclaimedQuestCount > 1 ? 's' : '' }} ready to claim.
        </router-link>
        <router-link v-if="hasUnclaimedBattlePass" to="/battle-pass" class="dashboard-alert dashboard-alert--reward">
          🎫 Battle Pass rewards ready to claim.
        </router-link>
        <router-link v-if="brokenGear.length" :to="repairLinkTarget" class="dashboard-alert dashboard-alert--broken">
          🔴 {{ brokenGear.map((row) => row.item.name).join(', ') }}
          {{ brokenGear.length > 1 ? 'are' : 'is' }} broken and giving you nothing until repaired!
        </router-link>
        <router-link v-if="nearBrokenGear.length" :to="repairLinkTarget" class="dashboard-alert dashboard-alert--danger">
          ⚠ {{ nearBrokenGear.map((row) => row.item.name).join(', ') }}
          {{ nearBrokenGear.length > 1 ? 'are' : 'is' }} almost broken — repair soon.
        </router-link>
        <router-link v-if="showGatherAlert" to="/trade-skills" class="dashboard-alert dashboard-alert--idle">
          ⛏ No gathering in progress — head to Gathering.
        </router-link>
        <router-link v-if="!isCrafting" to="/crafting" class="dashboard-alert dashboard-alert--idle">
          🔨 Crafting queue is empty — queue something up.
        </router-link>
        <router-link v-if="unspentPoints > 0" to="/skills" class="dashboard-alert dashboard-alert--idle">
          ✦ {{ unspentPoints }} unspent point{{ unspentPoints > 1 ? 's' : '' }} — spend them in Skills.
        </router-link>
        <router-link
          v-if="autoBattle && autoBattle.active"
          to="/battle"
          class="dashboard-alert dashboard-alert--auto-battle"
          :class="{ 'dashboard-alert--auto-battle-paused': autoBattle.paused }"
        >
          <span class="auto-battle-pulse" :class="{ 'auto-battle-pulse--paused': autoBattle.paused }">
            <span class="auto-battle-pulse__ring"></span>
            <span class="auto-battle-pulse__dot"></span>
          </span>
          {{ autoBattle.paused ? 'Auto-Attack paused' : 'Auto-Attack active' }} —
          {{ formatDuration(autoBattle.seconds_remaining) }} remaining
        </router-link>
        <router-link
          v-if="autoGather && autoGather.active"
          to="/trade-skills"
          class="dashboard-alert dashboard-alert--auto-battle"
        >
          <span class="auto-battle-pulse">
            <span class="auto-battle-pulse__ring"></span>
            <span class="auto-battle-pulse__dot"></span>
          </span>
          Auto-{{ autoGather.skill }} active — {{ formatDuration(autoGather.seconds_remaining) }} remaining
        </router-link>
      </div>

      <!-- Quick actions -->
      <div class="quick-actions">
        <router-link to="/battle" class="quick-actions__primary">⚔ Fight Monster</router-link>
        <router-link to="/quests" class="quick-actions__secondary">❖ Quests</router-link>
        <router-link to="/shop" class="quick-actions__secondary">◉ Shop</router-link>
        <router-link to="/trade-skills" class="quick-actions__secondary">
          ⛏ Gathering
          <span v-if="showGatherAlert" class="quick-actions__alert-badge">!</span>
        </router-link>
        <router-link to="/crafting" class="quick-actions__secondary">
          🔨 Crafting
          <span v-if="!isCrafting" class="quick-actions__alert-badge">!</span>
          <span v-else-if="hasReadyCraft" class="quick-actions__ready-badge">✓</span>
        </router-link>
      </div>

      <!-- Equipped -->
      <div class="equipped-eyebrow">EQUIPPED</div>
      <div class="equipped-grid">
        <div
          v-for="row in (store.character.inventory || []).filter((i) => i.equipped)"
          :key="row.id"
          class="equipped-item"
          :class="{
            'equipped-item--broken': gearDurabilityStatus(row) === 'broken',
            'equipped-item--low': gearDurabilityStatus(row) === 'low',
          }"
        >
          <div class="equipped-item__name">{{ row.item.glyph }} {{ row.item.name }}</div>
          <div class="equipped-item__type">{{ row.item.type }}</div>
          <div v-if="gearDurabilityStatus(row) === 'broken'" class="equipped-item__durability-tag equipped-item__durability-tag--broken">
            BROKEN
          </div>
          <div v-else-if="gearDurabilityStatus(row) === 'low'" class="equipped-item__durability-tag equipped-item__durability-tag--low">
            {{ Math.round((row.durability / row.durability_max) * 100) }}% durability
          </div>
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
      <router-link v-if="vipTimeLeft" to="/vip" class="vip-time-card">
        <div class="vip-time-card__header">
          <span class="ox vip-time-card__tier">👑 {{ vipTimeLeft.tier }} VIP</span>
        </div>
        <div class="vip-time-card__value">{{ vipTimeLeft.days }}d {{ vipTimeLeft.hours }}h remaining</div>
      </router-link>

      <div v-if="battlePass" class="battlepass-card">
        <div class="battlepass-card__header">
          <div class="ox battlepass-card__title">Ashfall Pass</div>
          <span class="battlepass-card__tier">Tier {{ battlePass.tier }}</span>
        </div>
        <router-link to="/battle-pass" class="battlepass-card__cta">View Battle Pass</router-link>
      </div>

      <div class="rail-card">
        <div class="rail-eyebrow">LEADERBOARD</div>
        <div
          v-for="l in leaders"
          :key="l.character_id"
          class="leaderboard-row"
          :style="l.banner ? { background: l.banner } : null"
        >
          <div class="leaderboard-row__left">
            <span class="ox leaderboard-row__rank">{{ l.rank }}</span>
            <span v-if="l.icon" class="leaderboard-row__icon">{{ l.icon }}</span>
            <span class="leaderboard-row__name" :style="l.name_color ? { color: l.name_color } : null">{{ l.name }}</span>
            <span v-if="l.title" class="leaderboard-row__title-badge">{{ l.title }}</span>
          </div>
          <span class="leaderboard-row__level">Lv.{{ l.level }}</span>
        </div>
        <div v-if="!leaders.length" class="rail-empty">No ranked characters yet.</div>
      </div>

      <router-link to="/trade-skills" class="rail-card rail-card--link">
        <div class="rail-eyebrow">TRADE SKILLS</div>
        <div v-for="skill in tradeSkills" :key="skill.key" class="trade-skill-row">
          <span class="trade-skill-row__name">{{ skill.glyph }} {{ skill.label }}</span>
          <span class="trade-skill-row__level">Lv.{{ skill.level }}</span>
        </div>
      </router-link>

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
