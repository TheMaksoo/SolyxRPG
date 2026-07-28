<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { useRegen } from '../composables/regen';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import WorldChat from '../components/WorldChat.vue';
import Skeleton from '../components/Skeleton.vue';

/** Gear counts as "low durability" once it drops to this fraction of max — worth a repair-pack nudge. */
const LOW_DURABILITY_PCT = 0.2;

const store = useCharacterStore();
const auth = useAuthStore();
// Shared HP/MP/energy regen projection (composables/regen.js) — same singleton state Battle reads, so
// the two pages never disagree and navigating between them never resets or re-seeds the projection.
// displayHp (not localHp) is what shows here — it automatically switches to the live in-combat HP
// whenever BattlePage reports an active battle, so this tile shows the exact same number Battle does
// even while a fight is in progress, instead of sitting frozen on the stale pre-battle HP.
const { displayHp, localMana, localEnergy, hpMax, mpMax, energyMax, isRegeneratingHp, isRegeneratingMana, isRegeneratingEnergy } = useRegen();
const daily = ref(null);
const battlePass = ref(null);
const leaders = ref([]);
const recentBattles = ref([]);
const quests = ref([]);
const announcements = ref([]);
const tradeSkills = ref([]);
const craftQueue = ref([]);
const craftRecipes = ref([]);
const craftMaxSlots = ref(0);
const unclaimedQuestCount = ref(0);
const railLoaded = ref(false);
const autoBattle = ref(null);
const autoGather = ref(null);

async function loadRail() {
  const [dailyRes, passRes, lbRes, recentBattlesRes, questRes, annRes, tradeRes, craftRes, recipeRes, autoBattleRes, autoGatherRes] = await Promise.all([
    api.get('/daily'),
    api.get('/battlepass'),
    api.get('/leaderboard'),
    api.get('/leaderboard/recent-battles'),
    api.get('/quests'),
    api.get('/announcements'),
    api.get('/trade-skills'),
    api.get('/crafting/queue'),
    api.get('/crafting/recipes'),
    api.get('/auto-battle'),
    api.get('/auto-gather'),
  ]);
  daily.value = dailyRes.data;
  battlePass.value = passRes.data.battle_pass;
  leaders.value = lbRes.data.leaderboard.slice(0, 5);
  recentBattles.value = recentBattlesRes.data.recent_battles;
  unclaimedQuestCount.value = questRes.data.quests.filter((q) => q.completed && !q.claimed).length;
  quests.value = questRes.data.quests.filter((q) => !q.completed).slice(0, 3);
  announcements.value = annRes.data.announcements.slice(0, 3);
  tradeSkills.value = tradeRes.data.trade_skills;
  craftQueue.value = craftRes.data.jobs;
  craftMaxSlots.value = craftRes.data.max_slots;
  craftRecipes.value = recipeRes.data.recipes;
  autoBattle.value = autoBattleRes.data;
  autoGather.value = autoGatherRes.data;
  railLoaded.value = true;
}

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  return `${Math.floor(seconds / 3600)}h ago`;
}

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

const isCrafting = computed(() => craftQueue.value.length > 0);

/** True if the queue actually has a free slot and at least one recipe is both level-unlocked and
 * affordable right now — nudging "queue something up" is pointless if nothing's actually craftable. */
const hasActionableCraft = computed(
  () => craftQueue.value.length < craftMaxSlots.value && craftRecipes.value.some((r) => r.can_craft)
);

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

// Cast to Number — the API can return these as numeric strings, and a naive `+` between a string
// and a number silently concatenates ("5" + 1 === "51") instead of adding.
const unspentAttributePoints = computed(() => Number(store.character?.attribute_points ?? 0));
const unspentSkillPoints = computed(() => Number(store.character?.skill_points ?? 0));
const unspentPoints = computed(() => unspentAttributePoints.value + unspentSkillPoints.value);

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

const xpPct = computed(() => {
  const currentXp = store.character?.xp ?? 0;
  const xpMax = store.character?.calculated_xp_max ?? 1;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  const xpRange = xpMax - xpMin;
  const xpProgress = currentXp - xpMin;
  return Math.min(100, Math.round((xpProgress / xpRange) * 100));
});

const xpCurrentLevel = computed(() => {
  const currentXp = store.character?.xp ?? 0;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  return Math.max(0, currentXp - xpMin);
});

const xpMaxLevel = computed(() => {
  const xpMax = store.character?.calculated_xp_max ?? 1;
  const xpMin = store.character?.calculated_xp_min ?? 0;
  return xpMax - xpMin;
});

onMounted(() => {
  store.fetch();
  loadRail();
});
</script>

<template>
  <!-- Every card shell below is always rendered — loading and loaded states differ only in what's
  inside a card, never in the card's own box (padding/width/border). That's what actually stops the
  page from jumping: there's no separate "skeleton dashboard" tree with its own guessed heights being
  swapped for a completely different "real dashboard" tree, so there's nothing to mismatch. -->
  <div class="dashboard">
    <div class="dashboard__chat-col">
      <WorldChat full-height />

      <div class="rail-card">
        <div class="rail-eyebrow">RECENT BATTLES</div>
        <template v-if="railLoaded">
          <div v-for="b in recentBattles" :key="b.character_id + '-' + b.created_at" class="leaderboard-row dash-fade-in">
            <div class="leaderboard-row__left">
              <span class="leaderboard-row__name">{{ b.name }}</span>
              <span class="rail-recent-battle__vs">vs {{ b.monster_name || 'a monster' }}</span>
            </div>
            <span class="leaderboard-row__level">Lv.{{ b.level }} · {{ timeAgo(b.created_at) }}</span>
          </div>
          <div v-if="!recentBattles.length" class="rail-empty dash-fade-in">Nobody's fighting right now.</div>
        </template>
        <template v-else>
          <Skeleton v-for="i in 5" :key="i" height="16px" style="margin: 8px 0" />
        </template>
      </div>

      <div class="rail-card">
        <div class="rail-eyebrow">ANNOUNCEMENTS</div>
        <template v-if="railLoaded">
          <div v-for="an in announcements" :key="an.id" class="announcement-row dash-fade-in">
            <div class="announcement-row__icon">📣</div>
            <div class="announcement-row__content">
              <div class="announcement-row__text">{{ an.body }}</div>
              <div class="announcement-row__author">{{ an.gm?.name }}</div>
            </div>
          </div>
          <div v-if="!announcements.length" class="rail-empty dash-fade-in">No announcements yet.</div>
        </template>
        <template v-else>
          <Skeleton v-for="i in 3" :key="i" height="30px" style="margin: 8px 0" />
        </template>
      </div>

      <AdBanner variant="sidebar" />
    </div>

    <div class="dashboard__main">
      <!-- Zone card -->
      <router-link to="/world-map" class="zone-card">
        <template v-if="store.character">
          <div class="zone-card__art dash-fade-in">
            {{ store.character.zone?.glyph ?? '🗺' }}
          </div>
          <div class="zone-card__body dash-fade-in">
            <div class="ox zone-card__name">{{ store.character.zone?.name ?? 'No zone selected' }}</div>
            <div class="zone-card__desc">
              {{ store.character.zone ? `Danger: ${store.character.zone.danger}` : 'Pick a zone in World Map to start fighting' }}
            </div>
            <span v-if="store.character.zone" class="zone-card__badge"
              >Recommended Lv.{{ store.character.zone.min_level }}+</span
            >
          </div>
        </template>
        <template v-else>
          <Skeleton variant="block" width="80px" height="80px" />
          <div class="zone-card__body">
            <Skeleton height="20px" width="60%" style="margin-bottom: 8px" />
            <Skeleton height="13px" width="85%" />
          </div>
        </template>
      </router-link>

      <!-- Stat tiles -->
      <div class="stat-tiles">
        <div class="stat-tile">
          <template v-if="store.character">
            <div class="xp-gauge dash-fade-in" :style="{ '--xp-pct': xpPct }">
              <div class="xp-gauge__inner">
                <div class="ox xp-gauge__level">{{ store.character.calculated_level }}</div>
                <div class="xp-gauge__tag">LVL</div>
              </div>
            </div>
            <div class="stat-tile__label">{{ xpCurrentLevel.toLocaleString() }} / {{ xpMaxLevel.toLocaleString() }} XP</div>
          </template>
          <template v-else>
            <Skeleton variant="circle" width="66px" height="66px" style="margin: 0 auto 6px" />
            <Skeleton height="12px" width="70%" style="margin: 0 auto" />
          </template>
        </div>
        <div class="stat-tile">
          <template v-if="store.character">
            <div class="ox stat-tile__value stat-tile__value--gold dash-fade-in">{{ store.character.gold }}</div>
            <div class="stat-tile__label">Gold</div>
          </template>
          <template v-else>
            <Skeleton height="30px" width="60%" style="margin: 0 auto 6px" />
            <Skeleton height="12px" width="50%" style="margin: 0 auto" />
          </template>
        </div>
        <div class="stat-tile">
          <template v-if="store.character">
            <div
              class="ox stat-tile__value stat-tile__value--hp dash-fade-in"
              :class="{ 'stat-tile__value--regenerating': isRegeneratingHp }"
            >{{ Math.round(displayHp) }}/{{ hpMax }}</div>
            <div class="stat-tile__label">HP</div>
            <div class="stat-tile__regen">💚 healing +{{ store.regenPerTick }}/5s</div>
          </template>
          <template v-else>
            <Skeleton height="24px" width="65%" style="margin: 0 auto 6px" />
            <Skeleton height="12px" width="40%" style="margin: 0 auto 4px" />
            <Skeleton height="10px" width="75%" style="margin: 0 auto" />
          </template>
        </div>
        <div class="stat-tile">
          <template v-if="store.character">
            <div
              class="ox stat-tile__value stat-tile__value--mana dash-fade-in"
              :class="{ 'stat-tile__value--regenerating': isRegeneratingMana }"
            >{{ Math.round(localMana) }}/{{ mpMax }}</div>
            <div class="stat-tile__label">Mana</div>
            <div class="stat-tile__regen">💧 regen +{{ store.manaRegenPerTick }}/5s</div>
          </template>
          <template v-else>
            <Skeleton height="24px" width="65%" style="margin: 0 auto 6px" />
            <Skeleton height="12px" width="40%" style="margin: 0 auto 4px" />
            <Skeleton height="10px" width="75%" style="margin: 0 auto" />
          </template>
        </div>
        <div class="stat-tile">
          <template v-if="store.character">
            <div
              class="ox stat-tile__value stat-tile__value--energy dash-fade-in"
              :class="{ 'stat-tile__value--regenerating': isRegeneratingEnergy }"
            >{{ Math.round(localEnergy) }}/{{ energyMax }}</div>
            <div class="stat-tile__label">Energy</div>
            <div class="stat-tile__regen">⚡ regen +{{ store.energyRegenPerTick }}/5s</div>
          </template>
          <template v-else>
            <Skeleton height="24px" width="65%" style="margin: 0 auto 6px" />
            <Skeleton height="12px" width="40%" style="margin: 0 auto 4px" />
            <Skeleton height="10px" width="75%" style="margin: 0 auto" />
          </template>
        </div>
      </div>

      <AdBanner variant="inline" />

      <!-- Alerts — kept last in this column so it can grow/shrink as its rows resolve (quests,
      gear durability, unspent points, live Training/Auto-Gather timers, etc.) without shifting
      anything above it around. TransitionGroup eases each row in/out and slides its neighbors over
      smoothly instead of the list snapping to its new size the instant a row appears or clears. -->
      <TransitionGroup tag="div" name="alert" class="dashboard-alerts">
        <router-link v-if="daily && daily.can_claim" key="daily" to="/daily" class="dashboard-alert dashboard-alert--reward">
          🎁 Your daily reward is ready to claim.
        </router-link>
        <router-link v-if="unclaimedQuestCount > 0" key="quests" to="/quests" class="dashboard-alert dashboard-alert--reward">
          ❖ {{ unclaimedQuestCount }} completed quest{{ unclaimedQuestCount > 1 ? 's' : '' }} ready to claim.
        </router-link>
        <router-link v-if="hasUnclaimedBattlePass" key="battlepass" to="/battle-pass" class="dashboard-alert dashboard-alert--reward">
          🎫 Battle Pass rewards ready to claim.
        </router-link>
        <router-link v-if="brokenGear.length" key="broken" :to="repairLinkTarget" class="dashboard-alert dashboard-alert--broken">
          🔴 {{ brokenGear.map((row) => row.item.name).join(', ') }}
          {{ brokenGear.length > 1 ? 'are' : 'is' }} broken and giving you nothing until repaired!
        </router-link>
        <router-link v-if="nearBrokenGear.length" key="near-broken" :to="repairLinkTarget" class="dashboard-alert dashboard-alert--danger">
          ⚠ {{ nearBrokenGear.map((row) => row.item.name).join(', ') }}
          {{ nearBrokenGear.length > 1 ? 'are' : 'is' }} almost broken — repair soon.
        </router-link>
        <router-link v-if="hasActionableCraft" key="craft" to="/crafting" class="dashboard-alert dashboard-alert--idle">
          🔨 Crafting queue is empty — queue something up.
        </router-link>
        <router-link v-if="unspentPoints > 0" key="points" to="/skills" class="dashboard-alert dashboard-alert--idle">
          ✦ Unspent:
          <template v-if="unspentAttributePoints > 0">{{ unspentAttributePoints }} attribute point{{ unspentAttributePoints > 1 ? 's' : '' }}</template>
          <template v-if="unspentAttributePoints > 0 && unspentSkillPoints > 0">, </template>
          <template v-if="unspentSkillPoints > 0">{{ unspentSkillPoints }} skill point{{ unspentSkillPoints > 1 ? 's' : '' }}</template>
          — spend them in Skills.
        </router-link>
        <router-link
          v-if="autoBattle && autoBattle.active"
          key="training"
          to="/battle"
          class="dashboard-alert dashboard-alert--auto-battle"
        >
          <span class="auto-battle-pulse">
            <span class="auto-battle-pulse__ring"></span>
            <span class="auto-battle-pulse__dot"></span>
          </span>
          Training active —
          {{ formatDuration(autoBattle.seconds_remaining) }} remaining
        </router-link>
        <router-link
          v-if="autoGather && autoGather.active"
          key="gathering"
          to="/trade-skills"
          class="dashboard-alert dashboard-alert--auto-battle"
        >
          <span class="auto-battle-pulse">
            <span class="auto-battle-pulse__ring"></span>
            <span class="auto-battle-pulse__dot"></span>
          </span>
          Auto-{{ autoGather.skill }} active — {{ formatDuration(autoGather.seconds_remaining) }} remaining
        </router-link>
      </TransitionGroup>
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
        <template v-if="railLoaded">
          <div
            v-for="l in leaders"
            :key="l.character_id"
            class="leaderboard-row dash-fade-in"
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
          <div v-if="!leaders.length" class="rail-empty dash-fade-in">No ranked characters yet.</div>
        </template>
        <template v-else>
          <Skeleton v-for="i in 5" :key="i" height="16px" style="margin: 8px 0" />
        </template>
      </div>

      <router-link to="/trade-skills" class="rail-card rail-card--link">
        <div class="rail-eyebrow">TRADE SKILLS</div>
        <template v-if="railLoaded">
          <div v-for="skill in tradeSkills" :key="skill.key" class="trade-skill-row dash-fade-in">
            <span class="trade-skill-row__name">{{ skill.glyph }} {{ skill.label }}</span>
            <span class="trade-skill-row__level">Lv.{{ skill.level }}</span>
          </div>
        </template>
        <!-- Always exactly 4 trade skills, so this skeleton's size is an exact match, not a guess. -->
        <template v-else>
          <Skeleton v-for="i in 4" :key="i" height="14px" style="margin: 7px 0" />
        </template>
      </router-link>

      <div class="rail-card">
        <div class="rail-eyebrow">ACTIVE QUESTS</div>
        <template v-if="railLoaded">
          <div v-for="row in quests" :key="row.quest.id" class="quest-item dash-fade-in">
            <div class="quest-item__name">{{ row.quest.name }}</div>
            <div class="quest-item__track">
              <div
                class="quest-item__fill"
                :style="{ width: Math.min(100, Math.round((row.progress / (row.quest.goal_json.target ?? 1)) * 100)) + '%' }"
              ></div>
            </div>
            <div class="quest-item__progress">{{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}</div>
          </div>
          <div v-if="!quests.length" class="rail-empty dash-fade-in">No active quests.</div>
        </template>
        <template v-else>
          <Skeleton v-for="i in 3" :key="i" height="40px" style="margin: 8px 0" />
        </template>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./DashboardPage.scss" scoped></style>
