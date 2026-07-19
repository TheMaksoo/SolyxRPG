<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const store = useCharacterStore();
const message = ref('');
const allSkills = ref([]);
const progressions = ref([]);

const ATTRS = [
  { key: 'damage', label: 'Damage', desc: '+5 ATK' },
  { key: 'armor', label: 'Armor', desc: '+4 DEF' },
  { key: 'hp_cap', label: 'HP Cap', desc: '+30 max HP' },
  { key: 'mana_cap', label: 'Mana Cap', desc: '+20 max MP' },
  { key: 'hp_regen', label: 'HP Regen', desc: '+1 HP/tick per 3 pts' },
  { key: 'mana_regen', label: 'Mana Regen', desc: '+1 MP/tick per 3 pts' },
  { key: 'crit', label: 'Crit Chance', desc: '+2% crit chance' },
  { key: 'crit_damage', label: 'Crit Damage', desc: '+2% crit damage' },
  { key: 'luck', label: 'Luck', desc: 'Better rarity rolls & item value' },
  { key: 'dodge', label: 'Dodge', desc: '+1% dodge chance (max 50%)' },
  { key: 'energy_cap', label: 'Energy Cap', desc: '+15 max Energy' },
  { key: 'energy_regen', label: 'Energy Regen', desc: '+1 Energy/tick per 3 pts' },
  { key: 'mining_speed', label: 'Mining Speed', desc: '-2% Mining time (max 40%)' },
  { key: 'chopping_speed', label: 'Chopping Speed', desc: '-2% Woodchopping time (max 40%)' },
  { key: 'smelting_speed', label: 'Smelting Speed', desc: '-2% Smelting time (max 40%)' },
  { key: 'crafting_speed', label: 'Crafting Speed', desc: '-2% Crafting time (max 40%)' },
  { key: 'foraging_speed', label: 'Foraging Speed', desc: '-2% Foraging time (max 40%)' },
];

const TIER_CAPS = { t20: 20, t40: 40, t60: 60 };
const TIER_COLUMN = { t20: 'spec_class', t40: 'profession', t60: 'ascension' };

async function load() {
  const [skillsRes, progRes] = await Promise.all([api.get('/skills'), api.get('/class-progressions')]);
  allSkills.value = skillsRes.data.skills;
  progressions.value = progRes.data.progressions;
  if (!store.character) await store.fetch();
}

const unlockedById = computed(() => {
  const map = new Map();
  for (const s of store.character?.skills || []) map.set(s.skill_id, s);
  return map;
});

const branches = computed(() => {
  const groups = {};
  for (const skill of allSkills.value) {
    (groups[skill.branch] ??= []).push(skill);
  }
  return Object.entries(groups).map(([branch, skills]) => ({
    branch,
    nodes: skills
      .sort((a, b) => a.tier - b.tier)
      .map((skill, i, arr) => {
        const owned = unlockedById.value.get(skill.id);
        const unlocked = !!owned;
        const prevUnlocked = i === 0 || unlockedById.value.has(arr[i - 1].id);
        const levelOk = (store.character?.level ?? 0) >= skill.level_req;
        const hasPoints = (store.character?.skill_points ?? 0) > 0;
        const maxed = unlocked && owned.level >= skill.max_level;
        const nextRank = (owned?.level ?? 0) + 1;
        const nextRankLevel = skill.rank_levels?.[nextRank - 1] ?? skill.level_req;
        const nextRankLevelOk = (store.character?.level ?? 0) >= nextRankLevel;
        const canUnlock = !unlocked && prevUnlocked && levelOk && hasPoints;
        const canUpgrade = unlocked && !maxed && hasPoints && nextRankLevelOk;

        return {
          skill,
          unlocked,
          level: owned?.level ?? 0,
          maxed,
          canUnlock,
          canUpgrade,
          effectDescription: owned?.effect_description,
          nextEffectDescription: owned?.next_rank_effect_description,
          locked: !unlocked && (!prevUnlocked || !levelOk),
          rankLocked: unlocked && !maxed && !nextRankLevelOk,
          nextRankLevel,
          reqText: !prevUnlocked ? 'Unlock previous skill first' : `Requires level ${skill.level_req}`,
        };
      }),
  }));
});

const tiers = ['t20', 't40', 't60'];
const classPath = computed(() =>
  tiers.map((tier) => {
    const options = progressions.value.filter((p) => p.tier === tier);
    const column = TIER_COLUMN[tier];
    const chosenKey = store.character?.[column];
    const chosen = options.find((o) => o.key === chosenKey);
    const cap = TIER_CAPS[tier];

    const priorColumn = tier === 't40' ? 'spec_class' : tier === 't60' ? 'profession' : null;
    const priorChosen = !priorColumn || !!store.character?.[priorColumn];

    return {
      tier,
      heading: tier.toUpperCase(),
      cap,
      chosen,
      isChosen: !!chosen,
      isChoose: !chosen && priorChosen && (store.character?.level ?? 0) >= cap,
      isLocked: !chosen && (!priorChosen || (store.character?.level ?? 0) < cap),
      reqText: !priorChosen ? 'Choose the previous tier first' : `Reach level ${cap}`,
      options,
    };
  })
);

async function spend(attr) {
  message.value = '';
  try {
    await store.spendAttribute(attr);
  } catch (e) {
    message.value = e.response?.data?.message || 'No points available.';
  }
}

async function unlockSkill(node) {
  message.value = '';
  try {
    await store.unlockSkill(node.skill.id);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not unlock or upgrade that skill.';
  }
}

async function pickProgression(tier, option) {
  message.value = '';
  try {
    await store.chooseProfession(tier, option.key);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not choose.';
  }
}

onMounted(load);
</script>

<template>
  <div v-if="store.character">
    <p v-if="message" class="skills-message">{{ message }}</p>

    <div class="skills-section-eyebrow">
      CLASS PATH — grow from your base class into a profession at each level cap
    </div>
    <div class="class-path-grid">
      <div v-for="t in classPath" :key="t.tier" class="class-tier-card">
        <div class="class-tier-card__header">
          <div class="class-tier-card__heading">{{ t.heading }}</div>
          <span class="class-tier-card__cap">Lv.{{ t.cap }}</span>
        </div>
        <div v-if="t.isChosen" class="class-tier-card__chosen">
          <div class="class-tier-card__chosen-glyph">{{ t.chosen.glyph }}</div>
          <div>
            <div class="ox class-tier-card__chosen-name">{{ t.chosen.name }}</div>
            <div class="class-tier-card__chosen-desc">{{ t.chosen.description }}</div>
          </div>
        </div>
        <div v-else-if="t.isChoose" class="class-tier-card__options">
          <button
            v-for="o in t.options"
            :key="o.id"
            @click="pickProgression(t.tier, o)"
            class="class-tier-card__option-btn"
          >
            <span class="class-tier-card__option-glyph">{{ o.glyph }}</span>
            <span><span class="class-tier-card__option-name">{{ o.name }}</span><br /><span class="class-tier-card__option-desc">{{ o.description }}</span></span>
          </button>
        </div>
        <div v-else class="class-tier-card__locked">🔒 {{ t.reqText }}</div>
      </div>
    </div>

    <div class="attributes-section">
      <div class="attributes-header">
        <div class="attributes-header__eyebrow">ATTRIBUTES — spend points earned on level up</div>
        <div class="attribute-points-badge">✦ {{ store.character.attribute_points }} attribute points</div>
      </div>
      <div class="attributes-grid">
        <div v-for="a in ATTRS" :key="a.key" class="attribute-card">
          <div>
            <div class="attribute-card__label">
              {{ a.label }} <span class="attribute-card__points">{{ store.character.attributes_?.[a.key] ?? 0 }} pts</span>
            </div>
            <div class="attribute-card__desc">{{ a.desc }}</div>
            <div class="attribute-card__cost">Costs {{ store.attributeCosts?.[a.key] ?? 1 }} pt{{ (store.attributeCosts?.[a.key] ?? 1) > 1 ? 's' : '' }}</div>
          </div>
          <button
            @click="spend(a.key)"
            :disabled="store.character.attribute_points < (store.attributeCosts?.[a.key] ?? 1)"
            class="attribute-card__btn"
          >
            +
          </button>
        </div>
      </div>

      <div class="skill-tree-header">
        <div class="skill-tree-header__eyebrow">SKILL TREE — abilities cost MP to cast in battle</div>
        <div class="skill-points-badge">◆ {{ store.character.skill_points }} skill points</div>
      </div>
      <div class="skill-branches-grid">
        <div v-for="col in branches" :key="col.branch">
          <div class="ox skill-branch__heading">{{ col.branch }}</div>
          <div class="skill-branch__nodes">
            <button
              v-for="nd in col.nodes"
              :key="nd.skill.id"
              @click="(nd.canUnlock || nd.canUpgrade) && unlockSkill(nd)"
              :disabled="!nd.canUnlock && !nd.canUpgrade"
              class="skill-node-btn"
              :class="{ 'is-unlocked': nd.unlocked }"
            >
              <div class="skill-node-btn__glyph">{{ nd.skill.glyph }}</div>
              <div class="skill-node-btn__name">
                {{ nd.skill.name }}
                <span v-if="nd.unlocked" class="skill-node-btn__rank">Rank {{ nd.level }}/{{ nd.skill.max_level }}</span>
              </div>
              <div class="skill-node-btn__desc">{{ nd.skill.description }}</div>
              <div class="skill-node-btn__cost">{{ nd.skill.mp_cost > 0 ? `${nd.skill.mp_cost} MP` : 'Passive' }}</div>
              <div class="skill-node-btn__exact">
                {{ nd.unlocked ? nd.effectDescription : nd.skill.preview_effect }}
              </div>
              <div v-if="nd.unlocked && nd.nextEffectDescription" class="skill-node-btn__next">
                Next rank: {{ nd.nextEffectDescription }}
              </div>
              <div
                class="skill-node-btn__status"
                :class="{ 'is-unlocked': nd.unlocked, 'is-available': nd.canUnlock || nd.canUpgrade }"
              >
                {{
                  nd.maxed ? 'Max rank'
                  : nd.canUpgrade ? 'Click to upgrade (+12%/rank)'
                  : nd.rankLocked ? `Rank ${nd.level + 1} requires level ${nd.nextRankLevel}`
                  : nd.unlocked ? 'No skill points'
                  : nd.canUnlock ? 'Click to unlock'
                  : nd.reqText
                }}
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./SkillsPage.scss" scoped></style>
