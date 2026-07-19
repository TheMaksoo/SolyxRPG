<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import api from '../api/client';
import Toast from '../components/Toast.vue';

const skills = ref([]);
const log = ref([]);
const energy = ref(0);
const energyMax = ref(0);
const message = ref('');
const messageType = ref('success');
const busyKey = ref(null);
let tickTimer = null;
let messageTimer = null;

const autoGather = ref({ active: false, seconds_remaining: 0, costs: {}, granted_minutes: {}, gems: 0 });
const autoGatherSummary = ref(null);
const autoGatherSkill = ref('mining');
const autoGatherTarget = ref('stone');
let autoGatherTimer = null;
let autoGatherPollTimer = null;

const GATHER_SKILLS = ['mining', 'woodchopping', 'foraging', 'smelting'];

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
  energy.value = data.energy;
  energyMax.value = data.energy_max;
  log.value = data.log;
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
}

watch(autoGatherSkill, (skillKey) => {
  const targets = unlockedTargetsFor(skillKey);
  if (!targets.some((t) => t.key === autoGatherTarget.value)) {
    autoGatherTarget.value = targets[0]?.key ?? '';
  }
});

async function buyAutoGather(minutes) {
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
    showMessage(`Auto-Gather started — ${autoGather.value.granted_minutes[minutes]} minutes added.`, 'success');
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not start Auto-Gather.', 'error');
  }
}

function xpPct(skill) {
  return Math.min(100, Math.round((skill.xp / (skill.xp_max || 1)) * 100));
}

function energyPct() {
  return Math.min(100, Math.round((energy.value / (energyMax.value || 1)) * 100));
}

function canWork(skill, target) {
  return target.unlocked && skill.cooldown_remaining === 0 && energy.value >= target.energy_cost && target.has_input;
}

function formatKey(key) {
  return key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function buttonLabel(skill, target) {
  if (!target.unlocked) return 'Locked';
  if (skill.cooldown_remaining > 0) {
    return target.key === skill.last_action_target ? `${skill.cooldown_remaining}s` : 'Locked';
  }
  if (!target.has_input) return `Need ${formatKey(target.input_key)}`;
  if (energy.value < target.energy_cost) return `Need ⚡${target.energy_cost}`;
  return ACTION_VERB[skill.key] || 'Go';
}

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  return `${Math.floor(seconds / 3600)}h ago`;
}

async function work(skill, target) {
  if (!canWork(skill, target) || busyKey.value === `${skill.key}:${target.key}`) return;
  busyKey.value = `${skill.key}:${target.key}`;
  try {
    const { data } = await api.post(`/trade-skills/${skill.key}/gather`, { target: target.key });
    const levelSuffix = data.leveled_up ? ` — ${skill.label} leveled up!` : '';
    showMessage(`+${data.gained.qty} ${data.gained.item.name}${levelSuffix}`, 'success');
    await load();
  } catch (e) {
    showMessage(e.response?.data?.message || 'Could not do that right now.', 'error');
  } finally {
    busyKey.value = null;
  }
}

onMounted(async () => {
  await load();
  loadAutoGather();
  tickTimer = setInterval(() => {
    for (const skill of skills.value) {
      if (skill.cooldown_remaining > 0) skill.cooldown_remaining -= 1;
    }
  }, 1000);
  autoGatherTimer = setInterval(() => {
    if (autoGather.value.seconds_remaining > 0) autoGather.value.seconds_remaining -= 1;
  }, 1000);
  autoGatherPollTimer = setInterval(loadAutoGather, 20000);
});

onUnmounted(() => {
  clearInterval(tickTimer);
  clearInterval(autoGatherTimer);
  clearInterval(autoGatherPollTimer);
  clearTimeout(messageTimer);
});
</script>

<template>
  <div>
    <Toast :message="message" :type="messageType" />

    <div class="trade-header">
      <div class="trade-header__icon">⛏</div>
      <h1 class="ox trade-title">Trade Skills</h1>
    </div>
    <p class="trade-subtitle">Gather raw materials, smelt them into bars, then put them to work in Crafting.</p>

    <div class="energy-bar">
      <div class="energy-bar__track">
        <div class="energy-bar__fill" :style="{ width: energyPct() + '%' }"></div>
      </div>
      <div class="energy-bar__label">⚡ {{ energy }} / {{ energyMax }} Energy</div>
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
          🤖 Auto-{{ autoGather.skill }} active — gathering {{ autoGather.target }}
        </span>
        <span class="auto-gather-card__timer">{{ formatDuration(autoGather.seconds_remaining) }} remaining</span>
      </div>
      <div class="auto-gather-card__buy">
        <span class="auto-gather-card__label">
          {{ autoGather.active ? '🤖 Buy more time' : '🤖 Auto-Gather — gathers for you while you\'re away' }}
        </span>
        <div class="auto-gather-card__picker">
          <select v-model="autoGatherSkill" class="auto-gather-card__select" :disabled="autoGather.active">
            <option v-for="key in GATHER_SKILLS" :key="key" :value="key">{{ formatKey(key) }}</option>
          </select>
          <select v-model="autoGatherTarget" class="auto-gather-card__select" :disabled="autoGather.active">
            <option v-for="t in unlockedTargetsFor(autoGatherSkill)" :key="t.key" :value="t.key">{{ t.label }}</option>
          </select>
        </div>
        <div class="auto-gather-card__options">
          <button
            v-for="minutes in [15, 30, 60]"
            :key="minutes"
            class="auto-gather-card__option"
            :disabled="!autoGatherTarget || (autoGather.gems ?? 0) < (autoGather.costs[minutes] ?? 0)"
            @click="buyAutoGather(minutes)"
          >
            {{ autoGather.granted_minutes[minutes] ?? minutes * 2 }}m · 💎{{ autoGather.costs[minutes] ?? '—' }}
          </button>
        </div>
      </div>
    </div>

    <div class="trade-skills-grid">
      <div v-for="skill in skills" :key="skill.key" class="trade-skill-card">
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
                :disabled="!canWork(skill, target) || busyKey === `${skill.key}:${target.key}`"
                @click="work(skill, target)"
              >
                {{ buttonLabel(skill, target) }}
              </button>
            </template>
            <template v-else>
              <div class="trade-target__info">
                <div class="trade-target__name trade-target__name--mystery">???</div>
                <div class="trade-target__meta">🔒 Unlocks at {{ skill.label }} level {{ target.unlock_level }}</div>
              </div>
              <span class="trade-target__locked-badge">🔒</span>
            </template>
          </div>
        </div>

        <p v-else class="trade-skill-card__note">
          Levels automatically as you craft — visit the Crafting page.
        </p>
      </div>
    </div>

    <div class="trade-log-eyebrow">RECENT ACTIVITY</div>
    <div class="trade-log">
      <div v-for="(entry, i) in log" :key="i" class="trade-log__row">
        <span class="trade-log__text">{{ entry.skill_label }}: +{{ entry.qty }} {{ entry.target_label }} (+{{ entry.xp }} xp)</span>
        <span class="trade-log__time">{{ timeAgo(entry.created_at) }}</span>
      </div>
      <div v-if="!log.length" class="trade-log__empty">No trade skill activity yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./TradeSkillsPage.scss" scoped></style>
