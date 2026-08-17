<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import api from '../api/client';
import Toast from '../components/Toast.vue';
import Skeleton from '../components/Skeleton.vue';
import ClickChallengeModal from '../components/ClickChallengeModal.vue';
import { useGameTick } from '../composables/gameTick';
import { useRegen } from '../composables/regen';
import { useCharacterStore } from '../stores/character';
import { useAutoGatherStore } from '../stores/autoGather';
import { useGatherCooldownsStore } from '../stores/gatherCooldowns';

const GATHER_FLUSH_TICKS = 1;
const AUTO_GATHER_POLL_TICKS = 20;

const { tickCount } = useGameTick();
const characterStore = useCharacterStore();
// The topbar's Auto-Gather pill (see stores/autoGather.js + GameLayout.vue) polls independently, but
// buying/switching a pass here shouldn't make it wait up to 20s to notice — nudge it to refresh right away.
const autoGatherStore = useAutoGatherStore();
// Feeds the topbar's gather-cooldown pill (see stores/gatherCooldowns.js) with data this page already
// fetched, so it updates instantly rather than waiting on that store's own independent poll.
const gatherCooldownsStore = useGatherCooldownsStore();
// Same shared HP/MP/energy projection every other page reads (Dashboard, Battle) — energy here used to be
// a static snapshot from the last /trade-skills load, so it never visibly ticked up between actions and
// could go stale enough to show "Need energy" after it had actually regenerated back. This makes the bar
// (and the gather-affordability checks below) track the same live number everywhere else in the game does.
const { localEnergy, energyMax, isRegeneratingEnergy } = useRegen();

const skills = ref([]);
const log = ref([]);
const logLoaded = ref(false);
const message = ref('');
const messageType = ref('success');
let messageTimer = null;

const autoGather = ref({ active: false, seconds_remaining: 0, costs: {}, granted_minutes: {}, gems: 0 });
const autoGatherSummary = ref(null);
const autoGatherSkill = ref('mining');
const autoGatherTarget = ref('stone');
const autoGatherBuying = ref(false);
const autoGatherSwitching = ref(false);

// Manual gather clicks are queued locally and flushed together every few ticks (or once 5 are
// queued) instead of firing one HTTP request per click — same server-side rules apply per action,
// just batched to cut request volume. Gathering (unlike combat) doesn't need to see one action's
// result before queuing the next, so this doesn't cost any responsiveness.
const gatherQueue = ref([]);
const gatherFlushing = ref(false);

const GATHER_SKILLS = ['mining', 'woodchopping', 'foraging', 'smelting'];

// Below this level, a manual gather click opens a quick 3-click minigame first instead of queuing
// instantly — a deliberate low-intensity "breather" beat between fights while the character is brand
// new (mirrors GmConfig's minigame_level_cap default). At/above this level it's back to the instant
// click-and-queue flow above, since by then gathering is a means to an end, not the point itself.
const MINIGAME_LEVEL_CAP = 5;
const MINIGAME_GLYPH = { mining: '⛏', woodchopping: '🪓', smelting: '🔥', foraging: '🌿' };
const minigameOpen = ref(false);
const minigamePending = ref(null);

function onMinigameComplete() {
  minigameOpen.value = false;
  if (minigamePending.value) {
    queueWork(minigamePending.value.skill, minigamePending.value.target);
    minigamePending.value = null;
  }
}

function onMinigameCancel() {
  minigameOpen.value = false;
  minigamePending.value = null;
}

const minigameGlyph = computed(() => MINIGAME_GLYPH[minigamePending.value?.skill?.key] ?? '🌿');
const minigameNoun = computed(() => (minigamePending.value ? formatKey(minigamePending.value.target.key) : 'it'));

function showMessage(text, type = 'success') {
  clearTimeout(messageTimer);
  message.value = text;
  messageType.value = type;
  messageTimer = setTimeout(() => { message.value = ''; }, 3000);
}

const ACTION_VERB = { mining: 'Mine', woodchopping: 'Chop', smelting: 'Smelt', foraging: 'Forage' };

async function load() {
  const { data } = await api.get('/trade-skills');
  skills.value = data.trade_skills;
  gatherCooldownsStore.setFromSkills(skills.value);
  // Feeds the shared regen baseline (composables/regen.js) rather than a page-local ref, so gathering's
  // energy display/afford-checks track the same continuously-projected number Dashboard shows, and a
  // gather action's authoritative server result re-seeds it instantly instead of waiting for a tick.
  if (characterStore.character) characterStore.character.energy = data.energy;
  log.value = data.log;
  logLoaded.value = true;
}

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function unlockedTargetsFor(skillKey) {
  const skill = skills.value.find((s) => s.key === skillKey);
  return skill ? skill.targets.filter((t) => t.unlocked) : [];
}

async function loadAutoGather() {
  const { data } = await api.get('/auto-gather');
  autoGather.value = data;
  if (data.active) {
    autoGatherSkill.value = data.skill;
    autoGatherTarget.value = data.target;
  }
  if (data.summary) {
    autoGatherSummary.value = data.summary;
    load();
  }
  // Feeds the topbar's Auto-Gather pill (see stores/autoGather.js) with data this page already fetched —
  // that store never hits /auto-gather itself, since reading it also ticks gathering forward server-side.
  autoGatherStore.setFromResponse(data);
}

watch(autoGatherSkill, (skillKey) => {
  const targets = unlockedTargetsFor(skillKey);
  if (!targets.some((t) => t.key === autoGatherTarget.value)) {
    autoGatherTarget.value = targets[0]?.key ?? '';
  }
});

async function buyAutoGather(minutes) {
  if (autoGatherBuying.value) return;
  autoGatherBuying.value = true;
  try {
    const { data } = await api.post('/auto-gather/purchase', {
      skill: autoGatherSkill.value,
      target: autoGatherTarget.value,
      minutes,
    });
    autoGather.value = {
      ...autoGather.value,
      active: true,
      skill: data.skill,
      target: data.target,
      seconds_remaining: data.seconds_remaining,
      gems: data.gems,
    };
    showMessage(`Auto-Gather started: ${autoGather.value.granted_minutes[minutes]} minutes added.`, 'success');
    autoGatherStore.setFromResponse(autoGather.value);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not start Auto-Gather.', 'error');
  } finally {
    autoGatherBuying.value = false;
  }
}

function isAutoGatherSwitchPending() {
  return autoGather.value.active
    && (autoGatherSkill.value !== autoGather.value.skill || autoGatherTarget.value !== autoGather.value.target);
}

async function switchAutoGatherTarget() {
  if (autoGatherSwitching.value || !isAutoGatherSwitchPending()) return;
  autoGatherSwitching.value = true;
  try {
    const { data } = await api.post('/auto-gather/switch', {
      skill: autoGatherSkill.value,
      target: autoGatherTarget.value,
    });
    autoGather.value = { ...autoGather.value, skill: data.skill, target: data.target };
    showMessage(`Auto-Gather switched to ${formatKey(data.target)}.`, 'success');
    load();
    autoGatherStore.setFromResponse(autoGather.value);
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not switch Auto-Gather target.', 'error');
  } finally {
    autoGatherSwitching.value = false;
  }
}

function xpPct(skill) {
  return Math.min(100, Math.round((skill.xp / (skill.xp_max || 1)) * 100));
}

function energyPct() {
  return Math.min(100, Math.round((localEnergy.value / (energyMax.value || 1)) * 100));
}

function actionKey(skill, target) {
  return `${skill.key}:${target.key}`;
}

function isQueued(skill, target) {
  return gatherQueue.value.some((a) => a.key === actionKey(skill, target));
}

function canWork(skill, target) {
  return target.unlocked && skill.cooldown_remaining === 0 && localEnergy.value >= target.energy_cost && target.has_input && !isQueued(skill, target);
}

function formatKey(key) {
  return key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// A locked target can be locked for either (or both) of two reasons — its own skill's unlock_level, and
// (Smelting's bars only) a specific level in the ore's own gathering skill. Naming both means a level-1
// smelter sees "requires Mining level 8" instead of a dead end.
function lockedReason(skill, target) {
  const reasons = [];
  if (skill.level < target.unlock_level) reasons.push(`${skill.label} level ${target.unlock_level}`);
  if (target.requires_skill && target.requires_skill_level) {
    reasons.push(`${formatKey(target.requires_skill)} level ${target.requires_skill_level}`);
  }
  return reasons.length ? `Requires ${reasons.join(' & ')}` : 'Locked';
}

function buttonLabel(skill, target) {
  if (!target.unlocked) return 'Locked';
  if (isQueued(skill, target)) return 'Queued…';
  if (skill.cooldown_remaining > 0) {
    return target.key === skill.last_action_target ? `${skill.cooldown_remaining}s` : 'Locked';
  }
  if (!target.has_input) return `Need ${formatKey(target.input_key)}`;
  if (localEnergy.value < target.energy_cost) return `Need ⚡${target.energy_cost}`;
  return ACTION_VERB[skill.key] || 'Go';
}

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  return `${Math.floor(seconds / 3600)}h ago`;
}

// Queues the click locally (instant visual feedback) instead of firing the request right away.
// Flushed in a batch every GATHER_FLUSH_TICKS ticks, or immediately once 5 actions are queued.
function work(skill, target) {
  if (!canWork(skill, target)) return;
  if ((characterStore.character?.level ?? 0) < MINIGAME_LEVEL_CAP) {
    minigamePending.value = { skill, target };
    minigameOpen.value = true;
    return;
  }
  queueWork(skill, target);
}

function queueWork(skill, target) {
  gatherQueue.value.push({ key: actionKey(skill, target), skillKey: skill.key, targetKey: target.key });
  // Optimistic cooldown so the button disables right away — corrected by load() once the batch resolves.
  // Uses THIS target's own action_seconds (tiers now take different amounts of time), not a flat per-skill
  // number.
  skill.cooldown_remaining = Math.max(skill.cooldown_remaining, target.action_seconds || 1);
  skill.last_action_target = target.key;
  gatherCooldownsStore.setFromSkills(skills.value);
  // Optimistic energy deduction, same idea — the bar should visibly drop the instant you click, not just
  // once the batch round-trips. Mutating characterStore.character.energy (not a local ref) re-seeds the
  // shared regen baseline immediately; load() corrects it to the real server value once the batch resolves.
  if (characterStore.character) {
    characterStore.character.energy = Math.max(0, characterStore.character.energy - target.energy_cost);
  }
  if (gatherQueue.value.length >= 5) flushGatherQueue();
}

async function flushGatherQueue() {
  if (gatherQueue.value.length === 0 || gatherFlushing.value) return;
  gatherFlushing.value = true;
  const batch = gatherQueue.value.splice(0, gatherQueue.value.length);
  try {
    const { data } = await api.post('/trade-skills/gather-batch', {
      actions: batch.map((a) => ({ skill: a.skillKey, target: a.targetKey })),
    });
    for (const result of data.results) {
      if (result.error) {
        showMessage(result.error, 'error');
      } else {
        const levelSuffix = result.leveled_up ? ' (leveled up!)' : '';
        showMessage(`+${result.gained.qty} ${result.gained.item.name}${levelSuffix}`, 'success');
      }
    }
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not process gathering.', 'error');
  } finally {
    gatherFlushing.value = false;
    await load();
  }
}

watch(tickCount, (count) => {
  for (const skill of skills.value) {
    if (skill.cooldown_remaining > 0) skill.cooldown_remaining -= 1;
  }
  if (autoGather.value.seconds_remaining > 0) autoGather.value.seconds_remaining -= 1;

  if (count % GATHER_FLUSH_TICKS === 0) flushGatherQueue();
  if (count % AUTO_GATHER_POLL_TICKS === 0) loadAutoGather();
});

onMounted(async () => {
  await load();
  loadAutoGather();
});

onUnmounted(() => {
  clearTimeout(messageTimer);
  flushGatherQueue();
});
</script>

<template>
  <div>
    <Toast :message="message" :type="messageType" />
    <ClickChallengeModal
      :open="minigameOpen"
      title="Gather"
      :glyph="minigameGlyph"
      :noun="minigameNoun"
      :clicks-required="3"
      @complete="onMinigameComplete"
      @cancel="onMinigameCancel"
    />

    <div class="trade-header">
      <div class="trade-header__icon">⛏</div>
      <h1 class="ox trade-title">Gathering</h1>
    </div>
    <p class="trade-subtitle">Gather raw materials, smelt them into bars, then put them to work in Crafting.</p>

    <div class="energy-bar">
      <div class="energy-bar__track">
        <div class="energy-bar__fill" :style="{ width: energyPct() + '%' }"></div>
      </div>
      <div class="energy-bar__label" :class="{ 'energy-bar__label--regenerating': isRegeneratingEnergy }">
        ⚡ {{ Math.round(localEnergy) }} / {{ energyMax }} Energy
      </div>
    </div>

    <div v-if="autoGatherSummary" class="claim-summary">
      <div class="claim-summary__header">
        <span class="ox claim-summary__title">🎉 While you were away</span>
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

    <div class="auto-gather-card">
      <div v-if="autoGather.active" class="auto-gather-card__status">
        <span class="auto-gather-card__label">
          🤖 Auto-{{ autoGather.skill }} active: gathering {{ autoGather.target }}
        </span>
        <span class="auto-gather-card__timer">{{ formatDuration(autoGather.seconds_remaining) }} remaining</span>
      </div>
      <div class="auto-gather-card__buy">
        <span class="auto-gather-card__label">
          {{ autoGather.active ? '🤖 Change target or buy more time' : '🤖 Auto-Gather: gathers for you while you\'re away' }}
        </span>
        <div class="auto-gather-card__picker">
          <select v-model="autoGatherSkill" class="auto-gather-card__select" :disabled="autoGatherBuying || autoGatherSwitching">
            <option v-for="key in GATHER_SKILLS" :key="key" :value="key">{{ formatKey(key) }}</option>
          </select>
          <select v-model="autoGatherTarget" class="auto-gather-card__select" :disabled="autoGatherBuying || autoGatherSwitching">
            <option v-for="t in unlockedTargetsFor(autoGatherSkill)" :key="t.key" :value="t.key">{{ t.label }}</option>
          </select>
        </div>
        <div v-if="isAutoGatherSwitchPending()" class="auto-gather-card__options">
          <button
            class="auto-gather-card__option"
            :disabled="autoGatherSwitching || !autoGatherTarget"
            @click="switchAutoGatherTarget"
          >
            {{ autoGatherSwitching ? 'Switching…' : `Switch to ${formatKey(autoGatherTarget)}` }}
          </button>
        </div>
        <div v-else class="auto-gather-card__options">
          <button
            v-for="minutes in [15, 30, 60]"
            :key="minutes"
            class="auto-gather-card__option"
            :disabled="autoGatherBuying || !autoGatherTarget || (autoGather.gems ?? 0) < (autoGather.costs[minutes] ?? 0)"
            @click="buyAutoGather(minutes)"
          >
            {{ autoGather.granted_minutes[minutes] ?? minutes * 2 }}m · 💎{{ autoGather.costs[minutes] ?? 'N/A' }}
          </button>
        </div>
      </div>
    </div>

    <div class="trade-skills-grid">
      <div
        v-for="skill in skills"
        :key="skill.key"
        class="trade-skill-card"
        :class="{ 'trade-skill-card--full': !skill.targets.length }"
      >
        <div class="trade-skill-card__head">
          <div class="trade-gauge" :style="{ '--xp-pct': xpPct(skill) }">
            <div class="trade-gauge__inner">
              <div class="ox trade-gauge__level">{{ skill.level }}</div>
            </div>
          </div>
          <div>
            <div class="ox trade-skill-card__name">{{ skill.glyph }} {{ skill.label }}</div>
            <div class="trade-skill-card__xp">{{ skill.xp }} / {{ skill.xp_max }} XP</div>
          </div>
        </div>
        <p class="trade-skill-card__desc">{{ skill.description }}</p>

        <div v-if="skill.targets.length" class="trade-targets">
          <div
            v-for="target in skill.targets"
            :key="target.key"
            class="trade-target"
            :class="{ 'trade-target--locked': !target.unlocked }"
          >
            <template v-if="target.unlocked">
              <div class="trade-target__info">
                <div class="trade-target__name">{{ target.label }}</div>
                <div class="trade-target__meta">
                  +{{ target.yield_qty }}/action · ⏱ {{ target.action_seconds }}s · ⚡{{ target.energy_cost }} · own {{ target.owned_qty }}
                  <span v-if="target.input_key" class="trade-target__input" :class="{ 'trade-target__input--missing': !target.has_input }">
                    · needs {{ target.required_input_qty }} {{ formatKey(target.input_key) }} (have {{ target.input_owned_qty }})
                  </span>
                </div>
              </div>
              <button
                class="trade-target__btn"
                :disabled="!canWork(skill, target)"
                @click="work(skill, target)"
              >
                {{ buttonLabel(skill, target) }}
              </button>
            </template>
            <template v-else>
              <div class="trade-target__info">
                <div class="trade-target__name trade-target__name--mystery">???</div>
                <div class="trade-target__meta">🔒 {{ lockedReason(skill, target) }}</div>
              </div>
              <span class="trade-target__locked-badge">🔒</span>
            </template>
          </div>
        </div>

        <p v-else class="trade-skill-card__note">
          Levels automatically as you craft. Visit the Crafting page.
        </p>
      </div>
    </div>

    <div class="trade-log-eyebrow">RECENT ACTIVITY</div>
    <div class="trade-log">
      <template v-if="!logLoaded">
        <Skeleton height="18px" style="margin: 6px 0" />
        <Skeleton height="18px" style="margin: 6px 0" />
        <Skeleton height="18px" style="margin: 6px 0" />
      </template>
      <template v-else>
        <div v-for="(entry, i) in log" :key="i" class="trade-log__row">
          <span class="trade-log__text">{{ entry.skill_label }}: +{{ entry.qty }} {{ entry.target_label }} (+{{ entry.xp }} xp)</span>
          <span class="trade-log__time">{{ timeAgo(entry.created_at) }}</span>
        </div>
        <div v-if="!log.length" class="trade-log__empty">No trade skill activity yet.</div>
      </template>
    </div>
  </div>
</template>

<style lang="scss" src="./TradeSkillsPage.scss" scoped></style>
