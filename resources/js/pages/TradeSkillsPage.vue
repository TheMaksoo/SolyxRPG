<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import api from '../api/client';

const skills = ref([]);
const message = ref('');
const busyKey = ref(null);
let tickTimer = null;

async function load() {
  const { data } = await api.get('/trade-skills');
  skills.value = data.trade_skills;
}

function xpPct(skill) {
  return Math.min(100, Math.round((skill.xp / (skill.xp_max || 1)) * 100));
}

async function gather(skill, target) {
  if (skill.action_seconds === 0 || skill.cooldown_remaining > 0 || !target.unlocked) return;
  message.value = '';
  busyKey.value = `${skill.key}:${target.key}`;
  try {
    const { data } = await api.post(`/trade-skills/${skill.key}/gather`, { target: target.key });
    message.value = `+${data.gained.qty} ${data.gained.item.name}${data.leveled_up ? ` — ${skill.label} leveled up!` : ''}`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not gather right now.';
  } finally {
    busyKey.value = null;
  }
}

onMounted(() => {
  load();
  tickTimer = setInterval(() => {
    for (const skill of skills.value) {
      if (skill.cooldown_remaining > 0) skill.cooldown_remaining -= 1;
    }
  }, 1000);
});

onUnmounted(() => clearInterval(tickTimer));
</script>

<template>
  <div>
    <div class="trade-header">
      <div class="trade-header__icon">⛏</div>
      <h1 class="ox trade-title">Trade Skills</h1>
    </div>
    <p class="trade-subtitle">Gather raw materials, smelt them into bars, then put them to work in Crafting.</p>

    <p v-if="message" class="trade-message">{{ message }}</p>

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
          <div v-for="target in skill.targets" :key="target.key" class="trade-target">
            <div class="trade-target__info">
              <div class="trade-target__name">
                {{ target.label }}
                <span v-if="!target.unlocked" class="trade-target__locked">🔒 Lv.{{ target.unlock_level }}</span>
              </div>
              <div class="trade-target__meta">
                +{{ target.yield_qty }}/action
                <span v-if="target.input_key"> · costs {{ target.input_qty * target.yield_qty }} {{ target.input_key.replace('_', ' ') }}</span>
              </div>
            </div>
            <button
              class="trade-target__btn"
              :disabled="!target.unlocked || skill.cooldown_remaining > 0 || busyKey === `${skill.key}:${target.key}`"
              @click="gather(skill, target)"
            >
              {{ skill.cooldown_remaining > 0 ? `${skill.cooldown_remaining}s` : 'Gather' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./TradeSkillsPage.scss" scoped></style>
