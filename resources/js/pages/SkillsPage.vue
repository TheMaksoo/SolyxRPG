<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';
import ConfirmModal from '../components/ConfirmModal.vue';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();
const auth = useAuthStore();
const message = ref('');
let errorToastTimer = null;
watch(message, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { message.value = ''; }, 5000);
});
const allSkills = ref([]);
const progressions = ref([]);
const selectedSkillId = ref(null);

// ---- GM-only: preview any class's skill tree without touching your own character ----
// A read-only content/balance-testing sandbox — previewClass swaps which class's skills/progressions
// are fetched (server-side gated to isGm() — see SkillController/ClassProgressionController), and
// previewPicks/previewUnlocked are a purely local, never-persisted simulation of choosing branches and
// investing points, so a GM can click all the way through a tree they don't personally have without
// ever calling an endpoint that could mutate a real character.
const isGm = computed(() => ['gm', 'owner'].includes(auth.user?.role));
const previewClass = ref(null);
const previewSkills = ref([]);
const previewProgressions = ref([]);
const previewPicks = ref({ t20: null, t50: null, t100: null, t150: null, t200: null });
const previewUnlocked = ref(new Map());
const previewing = computed(() => previewClass.value !== null);

async function startPreview(cls) {
  message.value = '';
  const [skillsRes, progRes] = await Promise.all([
    api.get('/skills', { params: { class: cls } }),
    api.get('/class-progressions', { params: { class: cls } }),
  ]);
  previewSkills.value = skillsRes.data.skills;
  previewProgressions.value = progRes.data.progressions;
  previewPicks.value = { t20: null, t50: null, t100: null, t150: null, t200: null };
  previewUnlocked.value = new Map();
  previewClass.value = cls;
  selectedSkillId.value = null;
}

function stopPreview() {
  previewClass.value = null;
  selectedSkillId.value = null;
}

function togglePreview(cls) {
  if (previewClass.value === cls) {
    stopPreview();
  } else {
    startPreview(cls);
  }
}

const activeSkills = computed(() => (previewing.value ? previewSkills.value : allSkills.value));
const activeProgressions = computed(() => (previewing.value ? previewProgressions.value : progressions.value));
const effectiveLevel = computed(() => (previewing.value ? 250 : (store.character?.level ?? 0)));
const effectiveSkillPoints = computed(() => (previewing.value ? 999 : (store.character?.skill_points ?? 0)));

function pickedKeyForTier(tier) {
  return previewing.value ? previewPicks.value[tier] : store.character?.[TIER_COLUMN[tier]];
}

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

const TIER_CAPS = { t20: 20, t50: 50, t100: 100, t150: 150, t200: 200 };
const TIER_COLUMN = { t20: 'spec_class', t50: 'profession', t100: 'ascension', t150: 'apex_path', t200: 'transcendence' };
// Each tier requires the one before it chosen first — a fully linear chain across all 5 rungs.
const TIER_PRIOR = { t20: null, t50: 't20', t100: 't50', t150: 't100', t200: 't150' };
const TIER_NAMES = { t20: 'SPECIALIZATION', t50: 'PROFESSION', t100: 'MASTERY', t150: 'TRANSCENDENCE', t200: 'ASCENSION' };

async function load() {
  const [skillsRes, progRes] = await Promise.all([api.get('/skills'), api.get('/class-progressions')]);
  allSkills.value = skillsRes.data.skills;
  progressions.value = progRes.data.progressions;
  if (!store.character) await store.fetch();
}

const unlockedById = computed(() => {
  const map = new Map();
  if (previewing.value) {
    for (const [skillId, level] of previewUnlocked.value) map.set(skillId, { skill_id: skillId, level });
    return map;
  }
  for (const s of store.character?.skills || []) map.set(s.skill_id, s);
  return map;
});

const skillsByKey = computed(() => {
  const map = new Map();
  for (const s of activeSkills.value) map.set(s.key, s);
  return map;
});

const chosenBranchKeys = computed(() => tiers.map((tier) => pickedKeyForTier(tier)));

/** Shared unlock-eligibility computation for exactly one node — resolves its prerequisite either from
 * `prereq_skill_key` (every signature-branch node — see SkillSeeder's treeNodes()) or, for the plain
 * core class skills which don't have one, from whichever sibling sits one tier below it in
 * `fallbackSiblings`. Both follow the identical "unlock the one before you first" rule
 * CharacterController::unlockSkill() enforces server-side. */
function buildNode(skill, fallbackSiblings = null) {
  let prevSkill = null;
  if (skill.prereq_skill_key) {
    prevSkill = skillsByKey.value.get(skill.prereq_skill_key) ?? null;
  } else if (fallbackSiblings) {
    const i = fallbackSiblings.findIndex((s) => s.id === skill.id);
    prevSkill = i > 0 ? fallbackSiblings[i - 1] : null;
  }

  const owned = unlockedById.value.get(skill.id);
  const unlocked = !!owned;
  const prevUnlocked = !prevSkill || unlockedById.value.has(prevSkill.id);
  const levelOk = effectiveLevel.value >= skill.level_req;
  const professionOk = !skill.requires_branch_key || chosenBranchKeys.value.includes(skill.requires_branch_key);
  // Mirrors CharacterController::effectiveSkillMaxLevel() — a level-150+ character gets 3 bonus ranks on
  // every skill (see GameConfig 'skill_mastery_unlock_level'/'skill_mastery_bonus_levels').
  const effectiveMaxLevel = skill.max_level + (effectiveLevel.value >= 150 ? 3 : 0);
  const maxed = unlocked && owned.level >= effectiveMaxLevel;
  const nextRank = (owned?.level ?? 0) + 1;
  const nextRankLevel = skill.rank_levels?.[nextRank - 1] ?? skill.level_req;
  const nextRankLevelOk = effectiveLevel.value >= nextRankLevel;
  // Rank N costs N skill points — unlocking (rank 1) is always 1 point. Matches
  // CharacterController::unlockSkill()'s cost curve.
  const cost = unlocked ? nextRank : 1;
  const hasPoints = effectiveSkillPoints.value >= cost;
  const canUnlock = !unlocked && prevUnlocked && levelOk && professionOk && hasPoints;
  const canUpgrade = unlocked && !maxed && hasPoints && nextRankLevelOk;

  return {
    skill,
    unlocked,
    level: owned?.level ?? 0,
    effectiveMaxLevel,
    maxed,
    cost,
    canUnlock,
    canUpgrade,
    effectDescription: owned?.effect_description,
    nextEffectDescription: owned?.next_rank_effect_description,
    locked: !unlocked && (!prevUnlocked || !levelOk || !professionOk),
    mystery: !unlocked && (!prevUnlocked || !professionOk),
    rankLocked: unlocked && !maxed && !nextRankLevelOk,
    nextRankLevel,
    reqText: !prevUnlocked
      ? '🔒 Unlock previous node first'
      : !levelOk
        ? `🔒 Requires level ${skill.level_req}`
        : !professionOk
          ? '🔒 Choose the matching Class Path branch first'
          : `🔒 Need ${cost} skill point${cost > 1 ? 's' : ''}`,
  };
}

/** The detail box's labeled "Cost" row — every resource spent on this node: MP per use, its cooldown,
 * and the skill point(s) needed to unlock/upgrade it next (omitted once it's already maxed). */
function costText(node) {
  const parts = [];
  if (node.skill.mp_cost > 0) parts.push(`${node.skill.mp_cost} MP`);
  if (node.skill.cooldown_rounds > 0) parts.push(`${node.skill.cooldown_rounds} round cooldown`);
  if (!node.maxed) parts.push(`${node.cost} skill point${node.cost > 1 ? 's' : ''} to ${node.unlocked ? 'upgrade' : 'unlock'}`);
  return parts.length ? parts.join(' · ') : 'Free';
}

/** The class's own always-available skills (Warfare/Sorcery/…, plus Mage's standalone Healing Light),
 * has its OWN branch point too now (see SkillSeeder's node_slot 'hub'/'left_cap'/'right_cap' on the
 * Warfare/Sorcery/Shadowcraft/Marksmanship skills) — trunk (T1, always) -> hub -> commit to LEFT
 * ("more damage") or RIGHT ("more debuffs"), same mutual-exclusion mechanic as the 40 signature
 * branches. A leftover utility skill with no offense/debuff pairing (Undying, Healing Light) is
 * surfaced separately, outside the branch choice entirely. */
const FOUNDATION_TRUNK_KEY = { warrior: 'power_strike', mage: 'shadow_bolt', rogue: 'precision_strikes', ranger: 'focused_aim' };
const FOUNDATION_UTILITY_KEY = { warrior: 'undying', mage: 'healing_light', rogue: 'whirling_blades' };
const foundationClass = computed(() => (previewing.value ? previewClass.value : store.character?.base_class));
const foundationTree = computed(() => {
  const cls = foundationClass.value;
  const trunkSkill = activeSkills.value.find((s) => s.key === FOUNDATION_TRUNK_KEY[cls]);
  const branch = trunkSkill?.branch;
  const node = (slot) => {
    const skill = activeSkills.value.find((s) => s.branch === branch && s.node_slot === slot);
    return skill ? buildNode(skill) : null;
  };
  const utilityKey = FOUNDATION_UTILITY_KEY[cls];
  const utilitySkill = utilityKey ? activeSkills.value.find((s) => s.key === utilityKey) : null;

  const trunk = trunkSkill ? [buildNode(trunkSkill)] : [];
  const utility = utilitySkill ? buildNode(utilitySkill) : null;
  const hub = node('hub');
  const left = [node('left_cap')].filter(Boolean);
  const right = [node('right_cap')].filter(Boolean);
  const committedArm = left[0]?.unlocked ? 'left' : right[0]?.unlocked ? 'right' : null;

  return { trunk, utility, hub, left, right, committedArm };
});

/** Full 9-node tree for one chosen signature branch (trunk x2 -> hub -> LEFT arm x3 / RIGHT arm x3) —
 * see SkillSeeder's treeNodes() for the exact same shape on the server side. Arm node display order
 * puts each arm's capstone on the OUTER edge and the hub in the center, mirroring the imported design's
 * own left-to-right flow (capstone, mid, mid, [HUB], mid, mid, capstone). */
function treeFor(branchKey) {
  const node = (slot) => {
    const skill = activeSkills.value.find((s) => s.requires_branch_key === branchKey && s.node_slot === slot);
    return skill ? buildNode(skill) : null;
  };
  const trunk = [node('trunk_1'), node('trunk_2')].filter(Boolean);
  const hub = node('hub');
  const left = [node('left_cap'), node('left_2'), node('left_1')].filter(Boolean);
  const right = [node('right_1'), node('right_2'), node('right_cap')].filter(Boolean);
  // Committed once EITHER arm's first node is owned — the other arm is then permanently given up
  // until a respec (see CharacterController::unlockSkill()'s mutual-exclusion check).
  const committedArm = node('left_1')?.unlocked ? 'left' : node('right_1')?.unlocked ? 'right' : null;

  return { trunk, hub, left, right, committedArm };
}

const tiers = ['t20', 't50', 't100', 't150', 't200'];
const classPath = computed(() =>
  tiers.map((tier) => {
    const options = activeProgressions.value.filter((p) => p.tier === tier);
    const chosenKey = pickedKeyForTier(tier);
    const chosen = options.find((o) => o.key === chosenKey);
    const cap = TIER_CAPS[tier];

    const priorTier = TIER_PRIOR[tier];
    const priorChosen = !priorTier || !!pickedKeyForTier(priorTier);

    return {
      tier,
      heading: TIER_NAMES[tier],
      cap,
      chosen,
      tree: chosen ? treeFor(chosen.key) : null,
      isChosen: !!chosen,
      isChoose: !chosen && priorChosen && effectiveLevel.value >= cap,
      isLocked: !chosen && (!priorChosen || effectiveLevel.value < cap),
      reqText: !priorChosen ? 'Choose the previous tier first' : `Reach level ${cap}`,
      options: options.map((o) => ({ ...o, previewNames: activeSkills.value.filter((s) => s.requires_branch_key === o.key && s.node_slot === 'left_cap').map((s) => s.name) })),
    };
  })
);

// Only the tiers that matter right now: every already-chosen gate (so a player can still open its tree
// back up to spend more points) plus exactly the next one in line — whether that's choosable today or
// still level-locked — rather than all 5 stacked at once, most of them just "🔒 Reach level N" boxes for
// gates a low-level character won't touch for a long time yet.
const visibleClassPath = computed(() => {
  const rows = [];
  for (const t of classPath.value) {
    rows.push(t);
    if (!t.isChosen) break;
  }
  return rows;
});

// Read-only rollup for the header — "X of 49 nodes unlocked, Y SP still unspent" (4 in Foundation + 9 per chosen gate).
const treeSummary = computed(() => {
  const f = foundationTree.value;
  let unlocked = [...f.trunk, f.hub, ...f.left, ...f.right].filter((n) => n?.unlocked).length;
  for (const t of classPath.value) {
    if (!t.tree) continue;
    unlocked += [...t.tree.trunk, t.tree.hub, ...t.tree.left, ...t.tree.right].filter((n) => n?.unlocked).length;
  }
  return { unlocked, total: 4 + classPath.value.filter((t) => t.isChosen).length * 9 };
});

const selectedNode = computed(() => {
  if (!selectedSkillId.value) return null;
  const skill = activeSkills.value.find((s) => s.id === selectedSkillId.value);
  return skill ? buildNode(skill) : null;
});

function selectNode(node) {
  selectedSkillId.value = node.skill.id;
}

// Double-click a node to unlock/upgrade it immediately — selects it first (so the sidebar reflects
// what just happened) then fires the same action the SELECTED NODE panel's button would, silently
// no-op'ing if it isn't actually eligible yet (mirrors that button's own disabled state).
function dblClickUnlock(node) {
  selectNode(node);
  if (node.canUnlock || node.canUpgrade) unlockSkill(node);
}

// One accent color per class (matches the imported "Solyx Skill Tree v3" design's per-route palette,
// simplified to per-class since hand-picking 40 distinct branch colors isn't worth the upkeep) — every
// chip/connector/medallion in a tier's tree card is tinted with this instead of one flat global accent.
const CLASS_ACCENT = { warrior: '#e8482f', mage: '#7c5cff', rogue: '#eab308', ranger: '#4ade80' };
const classAccent = computed(() => CLASS_ACCENT[previewing.value ? previewClass.value : store.character?.base_class] ?? '#e8482f');
const CLASS_GLYPH = { warrior: '⚔', mage: '✷', rogue: '🗡', ranger: '🏹' };
const classGlyph = computed(() => CLASS_GLYPH[previewing.value ? previewClass.value : store.character?.base_class] ?? '⚔');

// Passive (always-on, mp_cost 0) vs Active (costs MP, on the deck) vs the arm's granted Main Skill —
// mirrors the design's three node-type dots exactly (circle/blue, square/red, square/gold).
function nodeType(skill) {
  if (skill.node_slot === 'left_cap' || skill.node_slot === 'right_cap') return 'main';
  return skill.mp_cost > 0 ? 'active' : 'passive';
}

// Hover tooltip for any node chip (see the shared .tt/data-tip pattern already used on BattlePage) —
// condenses the same info the SELECTED NODE sidebar panel shows, so a quick hover answers "what does
// this do" without needing to click through first. Clicking still selects the node for the actual
// unlock/upgrade action, which a hover-only tooltip can't host.
function nodeTooltip(nd) {
  const effect = nd.unlocked ? nd.effectDescription : nd.skill.preview_effect;
  const status = nd.unlocked ? (nd.maxed ? 'Max rank' : `Rank ${nd.level}/${nd.effectiveMaxLevel}`) : nd.reqText;
  return [nd.skill.description, effect, status].filter(Boolean).join(' — ');
}

// ---- Active Deck (up to 6 unlocked active skills usable in battle — see CharacterController::setActiveDeck) ----
const unlockedActives = computed(() => (store.character?.skills || []).filter((row) => (row.skill?.mp_cost ?? 0) > 0));
const deckIds = computed(() => store.character?.active_deck_json || []);
const deckRows = computed(() => deckIds.value.map((id) => unlockedActives.value.find((r) => r.skill_id === id)).filter(Boolean));
const deckPool = computed(() => unlockedActives.value.filter((r) => !deckIds.value.includes(r.skill_id)));

async function toggleDeck(row) {
  message.value = '';
  const inDeck = deckIds.value.includes(row.skill_id);
  let next;
  if (inDeck) {
    next = deckIds.value.filter((id) => id !== row.skill_id);
  } else {
    if (deckIds.value.length >= 6) {
      message.value = 'Your Active Deck is full — remove one first.';
      return;
    }
    next = [...deckIds.value, row.skill_id];
  }
  try {
    await store.setActiveDeck(next);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not update deck.';
  }
}

async function spend(attr) {
  message.value = '';
  try {
    await store.spendAttribute(attr);
  } catch (e) {
    message.value = e.response?.data?.message || 'No points available.';
  }
}

/** Local-only mirror of CharacterController::unlockSkill()'s mutual-exclusion + free-capstone-grant
 * rules, used ONLY while GM-previewing a class — never calls the real API, so a preview can be clicked
 * through freely without ever touching a real character. */
function unlockSkillInPreview(node) {
  const skill = node.skill;
  const slot = skill.node_slot || '';
  const arm = slot.startsWith('left_') ? 'left_' : slot.startsWith('right_') ? 'right_' : null;

  if (arm) {
    const otherArm = arm === 'left_' ? 'right_' : 'left_';
    const givenUp = activeSkills.value.some(
      (s) => s.requires_branch_key === skill.requires_branch_key && (s.node_slot || '').startsWith(otherArm) && previewUnlocked.value.has(s.id)
    );
    if (givenUp) {
      message.value = 'Preview: you already committed to the other side of this branch — exit and re-enter the preview to reset it.';
      return;
    }
  }

  const next = new Map(previewUnlocked.value);
  next.set(skill.id, (next.get(skill.id) ?? 0) + 1);

  if (arm && slot.endsWith('_1')) {
    const cap = activeSkills.value.find((s) => s.requires_branch_key === skill.requires_branch_key && s.node_slot === `${arm}cap`);
    if (cap && !next.has(cap.id)) next.set(cap.id, 1);
  }

  previewUnlocked.value = next;
}

async function unlockSkill(node) {
  message.value = '';
  if (previewing.value) {
    unlockSkillInPreview(node);
    return;
  }
  try {
    await store.unlockSkill(node.skill.id);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not unlock or upgrade that skill.';
  }
}

async function pickProgression(tier, option) {
  message.value = '';
  if (previewing.value) {
    previewPicks.value = { ...previewPicks.value, [tier]: option.key };
    return;
  }
  try {
    await store.chooseProfession(tier, option.key);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not choose.';
  }
}

const respecCostGems = 1500;
const respecConfirmOpen = ref(false);
const respecBusy = ref(false);

function respec() {
  message.value = '';
  respecConfirmOpen.value = true;
}

async function confirmRespec() {
  respecBusy.value = true;
  try {
    await store.respecSkills();
    selectedSkillId.value = null;
    respecConfirmOpen.value = false;
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not respec.';
    respecConfirmOpen.value = false;
  } finally {
    respecBusy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div v-if="store.character" class="skills-page">
    <Toast :message="message" type="error" />

    <AdBanner variant="inline" />

    <div class="progression-header">
      <div class="ox progression-header__title">Progression</div>
      <div class="progression-header__subtitle">
        One ladder to 250 · five gates · one side each · {{ store.character.skill_points }} SP unspent · {{ treeSummary.unlocked }}/{{ treeSummary.total }} nodes
      </div>
      <div v-if="previewing" class="preview-banner">
        GM PREVIEW — {{ previewClass }} class tree, read-only sandbox, nothing here is saved
        <button class="preview-banner__exit" @click="stopPreview">Exit preview</button>
      </div>
    </div>

    <div class="skills-layout">
      <div class="skills-main">
        <!-- Class skills are their own gate section, styled EXACTLY like the LV.20-200 tiers below (same
        gate-header, gate medallion, vertical trunk, hub/branch-point) — including their OWN branch
        choice now: trunk -> hub -> commit to LEFT ("more damage") or RIGHT ("more debuffs") permanently,
        the identical mechanic every signature branch uses. A leftover utility skill with no natural
        offense/debuff pairing (Undying, Healing Light) sits below as its own always-available aside. -->
        <div class="gate-section">
          <div class="gate-header">
            <span class="gate-header__level-pill">LV.1</span>
            <span class="gate-header__heading">FOUNDATION</span>
            <span class="gate-header__state gate-header__state--chosen">{{ foundationTree.committedArm ? foundationTree[foundationTree.committedArm][0].skill.name : 'Class Skills' }}</span>
          </div>
          <div class="tree-card" :style="{ '--accent': classAccent }">
            <div class="tree-flow">
              <div class="gate-medallion">
                <div class="node-chip-sq node-chip-sq--gate is-unlocked">
                  <span class="node-chip-sq__glyph">{{ classGlyph }}</span>
                </div>
                <div class="node-label is-live">{{ foundationClass }}</div>
              </div>
              <div class="v-connector" :class="{ 'is-lit': foundationTree.trunk[0]?.unlocked }"></div>

              <template v-for="nd in foundationTree.trunk" :key="nd.skill.id">
                <div class="trunk-node">
                  <button
                    @click="selectNode(nd)"
                    @dblclick="dblClickUnlock(nd)"
                    class="node-chip-sq tt"
                    :data-tip="nodeTooltip(nd)"
                    :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }"
                  >
                    <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                    <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                    <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                  </button>
                  <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                </div>
                <div class="v-connector" :class="{ 'is-lit': nd.unlocked }"></div>
              </template>

              <div class="branch-point-row">
                <div class="arm-lane arm-lane--left" :class="{ 'is-given-up': foundationTree.committedArm === 'right' }">
                  <template v-for="nd in foundationTree.left" :key="nd.skill.id">
                    <div class="arm-lane__item">
                      <div class="node-col">
                        <button @click="selectNode(nd)" @dblclick="dblClickUnlock(nd)" class="node-chip-sq tt" :data-tip="nodeTooltip(nd)" :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }">
                          <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                          <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                          <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                        </button>
                        <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                      </div>
                      <div class="h-connector" :class="{ 'is-lit': nd.unlocked }"></div>
                    </div>
                  </template>
                </div>

                <div class="hub-col">
                  <div class="hub-col__label">BRANCH POINT</div>
                  <button
                    v-if="foundationTree.hub"
                    @click="selectNode(foundationTree.hub)"
                    @dblclick="dblClickUnlock(foundationTree.hub)"
                    class="node-chip-sq node-chip-sq--hub tt"
                    :data-tip="nodeTooltip(foundationTree.hub)"
                    :class="{ 'is-unlocked': foundationTree.hub.unlocked, 'is-open': !foundationTree.hub.unlocked && !foundationTree.hub.locked, 'is-selected': selectedSkillId === foundationTree.hub.skill.id }"
                  >
                    <span class="node-chip-sq__glyph">{{ foundationTree.hub.skill.glyph }}</span>
                    <span v-if="foundationTree.hub.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': foundationTree.hub.maxed }">{{ foundationTree.hub.level }}/{{ foundationTree.hub.effectiveMaxLevel }}</span>
                  </button>
                  <div v-if="foundationTree.hub" class="node-label" :class="{ 'is-live': foundationTree.hub.unlocked }">{{ foundationTree.hub.skill.name }}</div>
                  <div class="hub-col__warn">{{ foundationTree.committedArm ? `locked to ${foundationTree.committedArm}` : 'one side only' }}</div>
                </div>

                <div class="arm-lane arm-lane--right" :class="{ 'is-given-up': foundationTree.committedArm === 'left' }">
                  <template v-for="nd in foundationTree.right" :key="nd.skill.id">
                    <div class="arm-lane__item">
                      <div class="h-connector" :class="{ 'is-lit': foundationTree.hub?.unlocked }"></div>
                      <div class="node-col">
                        <button @click="selectNode(nd)" @dblclick="dblClickUnlock(nd)" class="node-chip-sq tt" :data-tip="nodeTooltip(nd)" :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }">
                          <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                          <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                          <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                        </button>
                        <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </div>

            <div v-if="foundationTree.utility" class="arm-footer">
              <div class="arm-footer__side">
                <span class="ox arm-footer__name">ALWAYS AVAILABLE</span>
                <div class="foundation-utility">
                  <button
                    @click="selectNode(foundationTree.utility)"
                    @dblclick="dblClickUnlock(foundationTree.utility)"
                    class="node-chip-sq node-chip-sq--sm tt"
                    :data-tip="nodeTooltip(foundationTree.utility)"
                    :class="{ 'is-unlocked': foundationTree.utility.unlocked, 'is-open': !foundationTree.utility.unlocked && !foundationTree.utility.locked, 'is-selected': selectedSkillId === foundationTree.utility.skill.id }"
                  >
                    <span class="node-chip-sq__glyph">{{ foundationTree.utility.skill.glyph }}</span>
                    <span class="node-chip-sq__type-dot" :class="`is-${nodeType(foundationTree.utility.skill)}`"></span>
                    <span v-if="foundationTree.utility.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': foundationTree.utility.maxed }">{{ foundationTree.utility.level }}/{{ foundationTree.utility.effectiveMaxLevel }}</span>
                  </button>
                  <div class="arm-footer__text">{{ foundationTree.utility.skill.name }}: {{ foundationTree.utility.skill.description }} (Lv.{{ foundationTree.utility.skill.level_req }}, outside the branch choice)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-for="t in visibleClassPath" :key="t.tier" class="gate-section">
          <div class="spine-connector spine-connector--v" :style="{ background: classAccent }"></div>
          <div class="gate-header">
            <span class="gate-header__level-pill">LV.{{ t.cap }}</span>
            <span class="gate-header__heading">{{ t.heading }}</span>
            <span v-if="t.isChosen" class="gate-header__state gate-header__state--chosen">{{ t.chosen.name }}</span>
            <span v-else-if="t.isChoose" class="gate-header__state gate-header__state--choose">CHOOSE</span>
            <span v-else class="gate-header__state">LOCKED</span>
          </div>

          <div v-if="t.isChoose" class="tier-card__options">
            <button v-for="o in t.options" :key="o.id" @click="pickProgression(t.tier, o)" class="branch-option">
              <div class="branch-option__head">
                <span class="branch-option__glyph">{{ o.glyph }}</span>
                <div>
                  <div class="ox branch-option__name">{{ o.name }}</div>
                  <div class="branch-option__desc">{{ o.description }}</div>
                </div>
              </div>
              <div v-if="o.bonus_text" class="branch-option__bonus">{{ o.bonus_text }}</div>
              <div class="branch-option__preview">Also unlocks: {{ o.previewNames.join(', ') }}</div>
            </button>
          </div>

          <div v-else-if="t.isChosen" class="tree-card" :style="{ '--accent': classAccent }">
            <div class="tree-flow">
              <!-- Gate medallion — the route's own icon, sitting at the top of the trunk exactly like the
              imported design's route header, instead of only appearing as text in the bar above. -->
              <div class="gate-medallion">
                <div class="node-chip-sq node-chip-sq--gate is-unlocked">
                  <span class="node-chip-sq__glyph">{{ t.chosen.glyph }}</span>
                </div>
                <div class="node-label is-live">{{ t.chosen.name }}</div>
              </div>
              <div class="v-connector" :class="{ 'is-lit': t.tree.trunk[0]?.unlocked }"></div>

              <!-- Trunk flows straight DOWN (not sideways) into the branch point below, matching the
              design's vertical trunk exactly. -->
              <template v-for="nd in t.tree.trunk" :key="nd.skill.id">
                <div class="trunk-node">
                  <button @click="selectNode(nd)" @dblclick="dblClickUnlock(nd)" class="node-chip-sq tt" :data-tip="nodeTooltip(nd)" :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }">
                    <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                    <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                    <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                  </button>
                  <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                </div>
                <div class="v-connector" :class="{ 'is-lit': nd.unlocked }"></div>
              </template>

              <div class="branch-point-row">
                <div class="arm-lane arm-lane--left" :class="{ 'is-given-up': t.tree.committedArm === 'right' }">
                  <template v-for="nd in t.tree.left" :key="nd.skill.id">
                    <div class="arm-lane__item">
                      <div class="node-col">
                        <button @click="selectNode(nd)" @dblclick="dblClickUnlock(nd)" class="node-chip-sq tt" :data-tip="nodeTooltip(nd)" :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }">
                          <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                          <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                          <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed, 'is-granted': nd.skill.node_slot === 'left_cap' && nd.level === 1 }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                        </button>
                        <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                      </div>
                      <div class="h-connector" :class="{ 'is-lit': nd.unlocked }"></div>
                    </div>
                  </template>
                </div>

                <div class="hub-col">
                  <div class="hub-col__label">BRANCH POINT</div>
                  <button @click="selectNode(t.tree.hub)" @dblclick="dblClickUnlock(t.tree.hub)" class="node-chip-sq node-chip-sq--hub tt" :data-tip="nodeTooltip(t.tree.hub)" :class="{ 'is-unlocked': t.tree.hub.unlocked, 'is-open': !t.tree.hub.unlocked && !t.tree.hub.locked, 'is-selected': selectedSkillId === t.tree.hub.skill.id }">
                    <span class="node-chip-sq__glyph">{{ t.tree.hub.skill.glyph }}</span>
                    <span v-if="t.tree.hub.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': t.tree.hub.maxed }">{{ t.tree.hub.level }}/{{ t.tree.hub.effectiveMaxLevel }}</span>
                  </button>
                  <div class="node-label" :class="{ 'is-live': t.tree.hub.unlocked }">{{ t.tree.hub.skill.name }}</div>
                  <div class="hub-col__warn">{{ t.tree.committedArm ? `locked to ${t.tree.committedArm}` : 'one side only' }}</div>
                </div>

                <div class="arm-lane arm-lane--right" :class="{ 'is-given-up': t.tree.committedArm === 'left' }">
                  <template v-for="(nd, i) in t.tree.right" :key="nd.skill.id">
                    <div class="arm-lane__item">
                      <div class="h-connector" :class="{ 'is-lit': (i === 0 ? t.tree.hub : t.tree.right[i - 1]).unlocked }"></div>
                      <div class="node-col">
                        <button @click="selectNode(nd)" @dblclick="dblClickUnlock(nd)" class="node-chip-sq tt" :data-tip="nodeTooltip(nd)" :class="{ 'is-unlocked': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked, 'is-selected': selectedSkillId === nd.skill.id }">
                          <span class="node-chip-sq__glyph">{{ nd.skill.glyph }}</span>
                          <span class="node-chip-sq__type-dot" :class="`is-${nodeType(nd.skill)}`"></span>
                          <span v-if="nd.unlocked" class="node-chip-sq__rank-pill" :class="{ 'is-maxed': nd.maxed, 'is-granted': nd.skill.node_slot === 'right_cap' && nd.level === 1 }">{{ nd.level }}/{{ nd.effectiveMaxLevel }}</span>
                        </button>
                        <div class="node-label" :class="{ 'is-live': nd.unlocked, 'is-open': !nd.unlocked && !nd.locked }">{{ nd.skill.name }}</div>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </div>

            <div class="arm-footer">
              <div class="arm-footer__side" :class="{ 'is-given-up': t.tree.committedArm === 'right' }">
                <span class="ox arm-footer__name">{{ t.tree.left[0]?.skill.effect_json?.arm_path_name ?? 'LEFT' }}</span>
                <span v-if="t.tree.committedArm === 'left'" class="arm-footer__pill arm-footer__pill--taken">★ {{ t.tree.left.find((n) => n.skill.node_slot === 'left_cap')?.skill.name }}</span>
                <span v-else-if="t.tree.committedArm === 'right'" class="arm-footer__pill">GIVEN UP</span>
                <div class="arm-footer__text">{{ t.tree.left[0]?.skill.description }}</div>
              </div>
              <div class="arm-footer__side" :class="{ 'is-given-up': t.tree.committedArm === 'left' }">
                <span class="ox arm-footer__name">{{ t.tree.right[0]?.skill.effect_json?.arm_path_name ?? 'RIGHT' }}</span>
                <span v-if="t.tree.committedArm === 'right'" class="arm-footer__pill arm-footer__pill--taken">★ {{ t.tree.right.find((n) => n.skill.node_slot === 'right_cap')?.skill.name }}</span>
                <span v-else-if="t.tree.committedArm === 'left'" class="arm-footer__pill">GIVEN UP</span>
                <div class="arm-footer__text">{{ t.tree.right[0]?.skill.description }}</div>
              </div>
            </div>
          </div>

          <div v-else class="tier-card__locked">🔒 {{ t.reqText }}</div>
        </div>
      </div>

      <div class="skills-sidebar">
        <div class="side-panel">
          <div class="side-panel__eyebrow">SELECTED NODE</div>
          <template v-if="selectedNode">
            <div class="detail-head">
              <span class="detail-head__glyph">{{ selectedNode.skill.glyph }}</span>
              <div>
                <div class="ox detail-head__name">{{ selectedNode.skill.name }}</div>
                <div class="detail-head__meta">{{ selectedNode.skill.mp_cost > 0 ? `${selectedNode.skill.mp_cost} MP` : 'Passive' }}</div>
              </div>
            </div>
            <div class="detail-desc">{{ selectedNode.skill.description }}</div>
            <div class="detail-stats">
              <div class="detail-stats__row">
                <span class="detail-stats__label">Cost</span>
                <span class="detail-stats__value">{{ costText(selectedNode) }}</span>
              </div>
              <div class="detail-stats__row">
                <span class="detail-stats__label">Total Benefit</span>
                <span class="detail-stats__value">{{ selectedNode.unlocked ? selectedNode.effectDescription : selectedNode.skill.preview_effect }}</span>
              </div>
            </div>
            <div v-if="selectedNode.unlocked && selectedNode.nextEffectDescription" class="detail-next">Next rank: {{ selectedNode.nextEffectDescription }}</div>
            <button
              v-if="selectedNode.canUnlock || selectedNode.canUpgrade"
              @click="unlockSkill(selectedNode)"
              class="detail-action"
            >
              {{ selectedNode.canUpgrade ? `Upgrade (${selectedNode.cost} pts)` : 'Unlock (1 pt)' }}
            </button>
            <div v-else class="detail-status">{{ selectedNode.maxed ? 'Max rank' : selectedNode.reqText }}</div>
          </template>
          <div v-else class="detail-empty">Click any node to inspect it.</div>
        </div>

        <div class="side-panel">
          <div class="side-panel__header">
            <span class="side-panel__eyebrow">ACTIVE DECK</span>
            <span class="ox deck-count" :class="{ 'is-full': deckIds.length >= 6 }">{{ deckIds.length }} / 6</span>
          </div>
          <div class="deck-note">Only skills in your deck are usable in battle — passives are always on.</div>
          <div class="deck-grid">
            <button v-for="row in deckRows" :key="row.skill_id" @click="toggleDeck(row)" class="deck-slot is-filled">
              <span class="deck-slot__glyph">{{ row.skill.glyph }}</span>
              <span class="deck-slot__name">{{ row.skill.name }}</span>
            </button>
            <div v-for="n in Math.max(0, 6 - deckRows.length)" :key="`empty-${n}`" class="deck-slot is-empty">—</div>
          </div>
          <div v-if="deckPool.length" class="deck-pool-label">UNLOCKED ACTIVES</div>
          <div class="deck-pool">
            <button v-for="row in deckPool" :key="row.skill_id" @click="toggleDeck(row)" class="deck-pool-row">
              <span>{{ row.skill.glyph }}</span>
              <span class="deck-pool-row__name">{{ row.skill.name }}</span>
              <span class="deck-pool-row__add">+</span>
            </button>
          </div>
        </div>

        <div class="side-panel">
          <div class="side-panel__header">
            <span class="side-panel__eyebrow">ATTRIBUTES</span>
            <span class="attribute-points-badge">✦ {{ store.character.attribute_points }}</span>
          </div>
          <div class="attr-list">
            <div v-for="a in ATTRS" :key="a.key" class="attr-row">
              <div class="attr-row__text">
                <div class="attr-row__label">{{ a.label }}</div>
                <div class="attr-row__desc">{{ a.desc }}</div>
              </div>
              <div class="ox attr-row__value">{{ store.character.attributes_?.[a.key] ?? 0 }}</div>
              <div class="attr-row__cost">{{ store.attributeCosts?.[a.key] ?? 1 }} pt</div>
              <button
                @click="spend(a.key)"
                :disabled="store.character.attribute_points < (store.attributeCosts?.[a.key] ?? 1)"
                class="attr-row__btn"
                :title="`Costs ${store.attributeCosts?.[a.key] ?? 1} pt`"
              >
                +
              </button>
            </div>
          </div>
        </div>

        <!-- Reuses Character::effectiveStats()'s own labeled breakdown (already computed server-side for
        the Profile page) rather than re-deriving these numbers — so "what's actually boosting my ATK/DEF
        right now" (attributes, gear, class branch passives, skill tree passives, …) can never drift out
        of sync with the real combat formula. -->
        <div v-if="store.stats?.breakdown" class="side-panel">
          <div class="side-panel__eyebrow">BUILD TOTAL</div>
          <div class="build-total-group">ATK</div>
          <div v-for="(row, i) in store.stats.breakdown.eff_atk" :key="`atk-${i}`" class="build-total-row">
            <span class="build-total-row__label">{{ row.label }}</span>
            <span class="ox build-total-row__value">{{ row.value }}</span>
          </div>
          <div class="build-total-group">DEF</div>
          <div v-for="(row, i) in store.stats.breakdown.eff_def" :key="`def-${i}`" class="build-total-row">
            <span class="build-total-row__label">{{ row.label }}</span>
            <span class="ox build-total-row__value">{{ row.value }}</span>
          </div>
        </div>

        <div class="side-panel">
          <div class="side-panel__eyebrow">SKILL POINTS</div>
          <div class="skill-points-total">◆ {{ store.character.skill_points }}</div>
          <button @click="respec" class="respec-btn">Respec skills — {{ respecCostGems }} gems</button>
          <div class="respec-note">Refunds every rank and reopens the side you gave up. Class choices reset too.</div>
        </div>

        <!-- GM-only content/balance testing tool — swaps which class's tree is displayed without ever
        touching a real character (see startPreview()/pickedKeyForTier() — server-side gated to isGm()
        too, see SkillController/ClassProgressionController). Picking branches and investing points while
        previewing is a local sandbox only, never persisted. -->
        <div v-if="isGm" class="side-panel side-panel--gm">
          <div class="side-panel__eyebrow">GM · PREVIEW CLASS TREE</div>
          <p class="gm-preview-desc">Read-only sandbox — inspect any class's full tree without touching your own character.</p>
          <div class="gm-class-switch">
            <button
              v-for="c in ['warrior', 'mage', 'rogue', 'ranger']"
              :key="c"
              class="gm-class-switch__btn"
              :class="{ 'is-active': previewClass === c }"
              @click="togglePreview(c)"
            >
              {{ c }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <ConfirmModal
      :open="respecConfirmOpen"
      title="Respec every skill?"
      :message="`This refunds all spent skill points, reopens every side you gave up, and lets you re-pick every Class Path branch — for ${respecCostGems} gems.`"
      confirm-label="Respec skills"
      danger
      :busy="respecBusy"
      @confirm="confirmRespec"
      @cancel="respecConfirmOpen = false"
    />
  </div>
</template>

<style lang="scss" src="./SkillsPage.scss" scoped></style>
