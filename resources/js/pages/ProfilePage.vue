<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const store = useCharacterStore();
const achievements = ref([]);

async function loadAchievements() {
  const { data } = await api.get('/achievements');
  achievements.value = data.achievements;
}

const ATTR_ROWS = [
  { key: 'damage', label: 'Damage' },
  { key: 'armor', label: 'Armor' },
  { key: 'hp_cap', label: 'HP Cap' },
  { key: 'mana_cap', label: 'Mana Cap' },
  { key: 'hp_regen', label: 'HP Regen' },
  { key: 'mana_regen', label: 'Mana Regen' },
  { key: 'crit', label: 'Crit Chance' },
  { key: 'crit_damage', label: 'Crit Damage' },
  { key: 'luck', label: 'Luck' },
  { key: 'dodge', label: 'Dodge' },
];

const CORE_ROWS = [
  { label: 'Attack', base: 'base_atk', eff: 'eff_atk' },
  { label: 'Defense', base: 'base_def', eff: 'eff_def' },
  { label: 'HP', base: 'hp_max', eff: 'eff_hp_max' },
  { label: 'Mana', base: 'mana_max', eff: 'eff_mp_max' },
];

const battlesTotal = computed(() => (store.character?.battles_won ?? 0) + (store.character?.battles_lost ?? 0));
const winRate = computed(() => (battlesTotal.value > 0 ? Math.round(((store.character?.battles_won ?? 0) / battlesTotal.value) * 100) : 0));

function activeBuff(pctKey, expiresKey) {
  const pct = store.character?.[pctKey];
  const expiresAt = store.character?.[expiresKey];
  if (!pct || !expiresAt) return null;
  const msLeft = new Date(expiresAt).getTime() - Date.now();
  if (msLeft <= 0) return null;
  return `+${pct}% for ${Math.max(1, Math.round(msLeft / 60000))}m`;
}

const hpRegenBuff = computed(() => activeBuff('hp_regen_buff_pct', 'hp_regen_buff_expires_at'));
const manaRegenBuff = computed(() => activeBuff('mana_regen_buff_pct', 'mana_regen_buff_expires_at'));

onMounted(() => {
  if (!store.character) store.fetch();
  loadAchievements();
});
</script>

<template>
  <div v-if="store.character">
    <div class="profile-hero">
      <div class="ox profile-hero__avatar">
        {{ store.character.name.slice(0, 2).toUpperCase() }}
      </div>
      <div class="profile-hero__info">
        <div class="ox profile-hero__name">{{ store.character.name }}</div>
        <div class="profile-hero__class">
          {{ store.character.spec_class || store.character.base_class }} · Level {{ store.character.level }}
        </div>
        <div class="profile-hero__meta">
          {{ store.character.battles_won }} won · {{ store.character.battles_lost ?? 0 }} lost ({{ winRate }}% win rate) · {{ store.character.bosses_slain }} bosses slain
        </div>
        <div v-if="store.stats" class="profile-hero__xp">
          <div class="profile-hero__xp-track">
            <div class="profile-hero__xp-fill" :style="{ width: Math.min(100, Math.round((store.character.xp / (store.stats.xp_max || 1)) * 100)) + '%' }"></div>
          </div>
          <div class="profile-hero__xp-label">{{ store.character.xp }} / {{ store.stats.xp_max }} XP</div>
        </div>
      </div>
      <div v-if="store.stats" class="profile-hero__stats">
        <div class="profile-stat">
          <div class="ox profile-stat__value profile-stat__value--power">{{ store.stats.power }}</div>
          <div class="profile-stat__label">Power</div>
        </div>
        <div class="profile-stat">
          <div class="ox profile-stat__value profile-stat__value--luck">{{ store.stats.luck ?? 0 }}</div>
          <div class="profile-stat__label">Luck</div>
        </div>
        <div class="profile-stat">
          <div class="ox profile-stat__value profile-stat__value--gold">{{ store.character.gold }}g</div>
          <div class="profile-stat__label">Gold</div>
        </div>
        <div class="profile-stat">
          <div class="ox profile-stat__value profile-stat__value--gems">{{ store.character.gems }}◆</div>
          <div class="profile-stat__label">Gems</div>
        </div>
      </div>
    </div>

    <div v-if="store.stats" class="stats-eyebrow">STATS — base value vs. total with gear, attributes &amp; buffs</div>
    <div v-if="store.stats" class="stats-panel">
      <table class="stats-table">
        <thead>
          <tr>
            <th></th>
            <th>Base</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in CORE_ROWS" :key="row.label">
            <td class="stats-table__label">{{ row.label }}</td>
            <td class="stats-table__base">{{ store.character[row.base] }}</td>
            <td class="stats-table__eff">{{ store.stats[row.eff] }}</td>
          </tr>
          <tr>
            <td class="stats-table__label">Crit Chance</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">{{ store.stats.crit_chance }}%</td>
          </tr>
          <tr>
            <td class="stats-table__label">Crit Damage</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">{{ store.stats.crit_damage_mult }}x</td>
          </tr>
          <tr>
            <td class="stats-table__label">Dodge Chance</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">{{ store.stats.dodge_chance ?? 0 }}%</td>
          </tr>
          <tr>
            <td class="stats-table__label">Luck</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">{{ store.stats.luck ?? 0 }}</td>
          </tr>
          <tr>
            <td class="stats-table__label">HP Regen</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">
              +{{ store.regenPerTick }}/5s
              <span v-if="hpRegenBuff" class="stats-table__buff">({{ hpRegenBuff }})</span>
            </td>
          </tr>
          <tr>
            <td class="stats-table__label">Mana Regen</td>
            <td class="stats-table__base">—</td>
            <td class="stats-table__eff">
              +{{ store.manaRegenPerTick }}/5s
              <span v-if="manaRegenBuff" class="stats-table__buff">({{ manaRegenBuff }})</span>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="attribute-points-eyebrow">ATTRIBUTE POINTS SPENT</div>
      <div class="attribute-points-grid">
        <div v-for="a in ATTR_ROWS" :key="a.key" class="attribute-points-chip">
          <span class="attribute-points-chip__label">{{ a.label }}</span>
          <span class="attribute-points-chip__value">{{ store.character.attributes_?.[a.key] ?? 0 }}</span>
        </div>
      </div>
    </div>

    <div class="achievements-eyebrow">ACHIEVEMENTS</div>
    <div class="achievements-grid">
      <div
        v-for="row in achievements"
        :key="row.achievement.id"
        class="achievement-card"
        :class="{ 'achievement-card--earned': row.earned }"
      >
        <div class="achievement-card__glyph">{{ row.achievement.glyph }}</div>
        <div class="achievement-card__name">{{ row.achievement.name }}</div>
        <div class="achievement-card__desc">{{ row.achievement.description }}</div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./ProfilePage.scss" scoped></style>
