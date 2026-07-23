<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import api from '../api/client';
import Toast from '../components/Toast.vue';
import { formatStats } from '../rarity';

const route = useRoute();

const recipes = ref([]);
const queue = ref([]);
const maxSlots = ref(1);
const craftingLevel = ref(1);
const rarityOdds = ref({});
const craftSpeedBonusPct = ref(0);
const message = ref('');
const messageType = ref('success');
let tickTimer = null;
let messageTimer = null;

function showMessage(text, type = 'success') {
  clearTimeout(messageTimer);
  message.value = text;
  messageType.value = type;
  messageTimer = setTimeout(() => { message.value = ''; }, 3000);
}

const SECTIONS = [
  { key: 'consumable', label: 'Consumables', glyph: '🧪' },
  { key: 'repair_pack', label: 'Repair Packs', glyph: '🧰' },
  { key: 'weapon', label: 'Weapons', glyph: '⚔' },
  { key: 'armor', label: 'Armor', glyph: '🛡' },
  { key: 'quiver', label: 'Quivers', glyph: '🎯' },
  { key: 'pickaxe', label: 'Pickaxes', glyph: '⛏' },
  { key: 'axe', label: 'Axes', glyph: '🪓' },
  { key: 'sickle', label: 'Sickles', glyph: '🔪' },
  { key: 'hammer', label: 'Hammers', glyph: '🔨' },
  { key: 'material', label: 'Materials', glyph: '🪨' },
];

function sectionKeyFor(type) {
  return type;
}

// Weapons/armor are the two sections where "which class is this even for" actually matters when
// scanning a long list — every other section (tools, potions, repair packs) is either class-agnostic
// or already small enough not to need it.
const CLASS_ORDER = ['warrior', 'mage', 'rogue', 'ranger'];
const GROUPED_SECTIONS = ['weapon', 'armor', 'consumable'];

const sections = computed(() =>
  SECTIONS.map((section) => {
    const sectionRecipes = recipes.value.filter((r) => sectionKeyFor(r.result_item.type) === section.key);

    // Group consumables by heal type (HP/MP/Regen)
    if (section.key === 'consumable') {
      const groups = [
        {
          key: 'hp',
          label: '❤️ HP Healing',
          recipes: sectionRecipes.filter((r) =>
            r.result_item.stat_json?.heal_hp_pct !== undefined ||
            r.result_item.stat_json?.heal_hp_flat !== undefined
          ),
        },
        {
          key: 'mp',
          label: '💧 MP Healing',
          recipes: sectionRecipes.filter((r) =>
            (r.result_item.stat_json?.heal_mp_pct !== undefined ||
              r.result_item.stat_json?.heal_mp_flat !== undefined) &&
            r.result_item.stat_json?.heal_hp_pct === undefined &&
            r.result_item.stat_json?.heal_hp_flat === undefined
          ),
        },
        {
          key: 'regen',
          label: '🌿 Regen Buffs',
          recipes: sectionRecipes.filter((r) =>
            r.result_item.stat_json?.hp_regen_pct_buff !== undefined ||
            r.result_item.stat_json?.mana_regen_pct_buff !== undefined
          ),
        },
        {
          key: 'other',
          label: '✨ Other',
          recipes: sectionRecipes.filter((r) =>
            !r.result_item.stat_json?.heal_hp_pct &&
            !r.result_item.stat_json?.heal_hp_flat &&
            !r.result_item.stat_json?.heal_mp_pct &&
            !r.result_item.stat_json?.heal_mp_flat &&
            !r.result_item.stat_json?.hp_regen_pct_buff &&
            !r.result_item.stat_json?.mana_regen_pct_buff
          ),
        },
      ].filter((g) => g.recipes.length);

      return { ...section, recipes: sectionRecipes, groups };
    }

    // Group weapons/armor by class
    if (!GROUPED_SECTIONS.includes(section.key)) {
      return { ...section, recipes: sectionRecipes, groups: null };
    }

    const groups = CLASS_ORDER.map((cls) => ({
      key: cls,
      label: cls.charAt(0).toUpperCase() + cls.slice(1),
      recipes: sectionRecipes.filter((r) => r.result_item.class_key === cls),
    })).filter((g) => g.recipes.length);

    return { ...section, recipes: sectionRecipes, groups };
  }).filter((section) => section.recipes.length)
);

// Minimized (collapsed) sections persist across visits so a player who only cares about
// consumables can fold away the gear categories once and not redo it every time they craft.
const COLLAPSE_STORAGE_KEY = 'solyx_crafting_collapsed_sections';
const collapsedSections = ref(new Set(JSON.parse(localStorage.getItem(COLLAPSE_STORAGE_KEY) || '[]')));

function isCollapsed(key) {
  return collapsedSections.value.has(key);
}

function toggleSection(key) {
  const next = new Set(collapsedSections.value);
  next.has(key) ? next.delete(key) : next.add(key);
  collapsedSections.value = next;
  localStorage.setItem(COLLAPSE_STORAGE_KEY, JSON.stringify([...next]));
}

async function load() {
  const [recipesRes, queueRes] = await Promise.all([api.get('/crafting/recipes'), api.get('/crafting/queue')]);
  recipes.value = recipesRes.data.recipes;
  craftingLevel.value = recipesRes.data.crafting_level;
  rarityOdds.value = recipesRes.data.rarity_odds;
  craftSpeedBonusPct.value = recipesRes.data.craft_speed_bonus_pct;
  queue.value = queueRes.data.jobs;
  maxSlots.value = queueRes.data.max_slots;
}

const queueFull = () => queue.value.length >= maxSlots.value;

async function craft(recipe) {
  try {
    await api.post(`/crafting/${recipe.id}/craft`);
    showMessage(`Queued ${recipe.result_item.name}.`, 'success');
    await load();
  } catch (e) {
    showMessage(e.response?.data?.message || 'Missing materials.', 'error');
  }
}

function craftButtonLabel(recipe) {
  if (!recipe.level_unlocked) return `Requires Lv.${recipe.min_level}`;
  if (!recipe.can_afford_gold) return 'Not Enough Gold';
  if (!recipe.can_craft) return 'Missing Materials';
  if (queueFull()) return 'Queue full';
  return 'Craft';
}

async function collect(job) {
  try {
    const { data } = await api.post(`/crafting/jobs/${job.id}/collect`);
    const rarityLabel = rarityOdds.value[job.rarity]?.label ?? job.rarity;
    showMessage(`Collected ${data.crafted_item.item.name} (${rarityLabel})!`, 'success');
    await load();
  } catch (e) {
    showMessage(e.response?.data?.message || 'Not ready yet.', 'error');
  }
}

onMounted(() => {
  load().then(() => {
    const targetSection = route.query.section;
    if (!targetSection) return;
    nextTick(() => {
      document.getElementById(`section-${targetSection}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  tickTimer = setInterval(() => {
    for (const job of queue.value) {
      if (job.seconds_remaining > 0) {
        job.seconds_remaining -= 1;
        if (job.seconds_remaining <= 0) job.is_ready = true;
      }
    }
  }, 1000);
});

onUnmounted(() => {
  clearInterval(tickTimer);
  clearTimeout(messageTimer);
});
</script>

<template>
  <div>
    <Toast :message="message" :type="messageType" />

    <div class="crafting-header">
      <div class="crafting-header__icon">🔨</div>
      <h1 class="ox crafting-title">Crafting</h1>
    </div>

    <p class="crafting-subtitle">
      Crafting rank {{ craftingLevel }} — better rank means better odds at rare-and-up rarity.
      <span v-if="craftSpeedBonusPct">VIP speeds up your crafts by {{ craftSpeedBonusPct }}%.</span>
    </p>

    <div class="rarity-odds-strip">
      <span v-for="(tier, key) in rarityOdds" :key="key" class="rarity-odds-chip" :class="{ 'is-locked': !tier.unlocked }" :style="{ color: tier.color }">
        {{ tier.label }}: {{ tier.unlocked ? `${tier.pct}%` : `🔒 Lv.${tier.unlock_level}` }}
      </span>
    </div>

    <div class="crafting-queue-eyebrow">CRAFTING QUEUE — {{ queue.length }} / {{ maxSlots }} slots</div>
    <div v-if="queue.length" class="crafting-queue-grid">
      <div v-for="job in queue" :key="job.id" class="queue-card">
        <span class="queue-card__glyph">{{ job.result_item.glyph }}</span>
        <div class="queue-card__body">
          <div class="ox queue-card__name">
            {{ job.result_item.name }}
            <span class="queue-card__rarity" :style="{ color: rarityOdds[job.rarity]?.color }">{{ rarityOdds[job.rarity]?.label ?? job.rarity }}</span>
            <span v-if="job.roll_pct !== null" class="queue-card__roll" :class="{ 'is-good': job.roll_pct > 0, 'is-bad': job.roll_pct < 0 }">
              {{ job.roll_pct > 0 ? '+' : '' }}{{ job.roll_pct }}% roll
            </span>
          </div>
          <div v-if="formatStats(job.result_item.stat_json).length" class="queue-card__stats">
            {{ formatStats(job.result_item.stat_json).join(' · ') }}
          </div>
          <div class="queue-card__status">{{ job.is_ready ? 'Ready to collect!' : `${job.seconds_remaining}s remaining` }}</div>
        </div>
        <button class="queue-card__collect-btn" :disabled="!job.is_ready" @click="collect(job)">Collect</button>
      </div>
    </div>
    <p v-else class="crafting-queue-empty">Queue is empty — craft something below.</p>

    <div v-for="section in sections" :key="section.key" :id="`section-${section.key}`">
      <button type="button" class="recipe-section-eyebrow recipe-section-eyebrow--toggle" @click="toggleSection(section.key)">
        <span>{{ section.glyph }} {{ section.label.toUpperCase() }}</span>
        <span class="recipe-section-eyebrow__count">{{ section.recipes.length }}</span>
        <span class="recipe-section-eyebrow__chevron" :class="{ 'is-collapsed': isCollapsed(section.key) }">▾</span>
      </button>
      <div v-show="!isCollapsed(section.key)">
        <template v-for="group in (section.groups && section.groups.length ? section.groups : [{ key: 'all', label: null, recipes: section.recipes }])" :key="group.key">
          <div v-if="group.label" class="recipe-class-group-label">{{ group.label }}</div>
          <div class="recipe-grid">
            <div
              v-for="recipe in group.recipes"
              :key="recipe.id"
              class="recipe-card"
              :class="{ 'recipe-card--locked': !recipe.can_craft }"
            >
              <div class="recipe-card__head">
                <span class="recipe-card__glyph">{{ recipe.result_item.glyph }}</span>
                <span class="ox recipe-card__name">{{ recipe.name }}</span>
                <span v-if="recipe.other_class" class="recipe-card__other-class" :title="`Made for the ${recipe.result_item.class_key} class — craft it anyway to sell on the Marketplace.`">
                  {{ recipe.result_item.class_key }} gear
                </span>
                <span v-if="recipe.result_qty > 1" class="recipe-card__qty">×{{ recipe.result_qty }}</span>
              </div>
              <div v-if="formatStats(recipe.result_item.stat_json).length" class="recipe-card__stats">
                <span v-for="stat in formatStats(recipe.result_item.stat_json)" :key="stat" class="recipe-card__stat">{{ stat }}</span>
              </div>
              <div class="recipe-card__label">
                Requires resources:
              </div>
              <div class="recipe-card__materials">
                <div
                  v-for="material in recipe.materials_detailed"
                  :key="material.item_id"
                  class="material-row"
                  :class="{ 'material-row--missing': !material.has_enough }"
                >
                  {{ material.glyph }} {{ material.name }}: {{ material.owned_qty }}/{{ material.required_qty }}
                </div>
              </div>
              <div
                v-if="recipe.gold_cost > 0"
                class="material-row"
                :class="{ 'material-row--missing': !recipe.can_afford_gold }"
              >
                🪙 {{ recipe.gold_cost }} gold
              </div>
              <div class="recipe-card__time">
                ⏱ {{ recipe.craft_seconds }}s to craft
                <span v-if="!recipe.level_unlocked" class="recipe-card__locked">🔒 Requires level {{ recipe.min_level }}</span>
              </div>
              <button
                @click="craft(recipe)"
                :disabled="!recipe.can_craft || queueFull()"
                class="recipe-card__craft-btn"
              >
                {{ craftButtonLabel(recipe) }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./CraftingPage.scss" scoped></style>
