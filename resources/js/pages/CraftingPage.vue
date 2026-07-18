<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import api from '../api/client';

const recipes = ref([]);
const queue = ref([]);
const craftingLevel = ref(1);
const rarityOdds = ref({});
const craftSpeedBonusPct = ref(0);
const message = ref('');
let tickTimer = null;

async function load() {
  const [recipesRes, queueRes] = await Promise.all([api.get('/crafting/recipes'), api.get('/crafting/queue')]);
  recipes.value = recipesRes.data.recipes;
  craftingLevel.value = recipesRes.data.crafting_level;
  rarityOdds.value = recipesRes.data.rarity_odds;
  craftSpeedBonusPct.value = recipesRes.data.craft_speed_bonus_pct;
  queue.value = queueRes.data.jobs;
}

const hasActiveJob = () => queue.value.length > 0;

async function craft(recipe) {
  message.value = '';
  try {
    await api.post(`/crafting/${recipe.id}/craft`);
    message.value = `Queued ${recipe.result_item.name} — check the queue below.`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Missing materials.';
  }
}

async function collect(job) {
  message.value = '';
  try {
    const { data } = await api.post(`/crafting/jobs/${job.id}/collect`);
    const rarityLabel = rarityOdds.value[job.rarity]?.label ?? job.rarity;
    message.value = `Collected ${data.crafted_item.item.name} (${rarityLabel})!`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Not ready yet.';
  }
}

onMounted(() => {
  load();
  tickTimer = setInterval(() => {
    for (const job of queue.value) {
      if (job.seconds_remaining > 0) {
        job.seconds_remaining -= 1;
        if (job.seconds_remaining <= 0) job.is_ready = true;
      }
    }
  }, 1000);
});

onUnmounted(() => clearInterval(tickTimer));
</script>

<template>
  <div>
    <div class="crafting-header">
      <div class="crafting-header__icon">🔨</div>
      <h1 class="ox crafting-title">Crafting</h1>
    </div>

    <p v-if="message" class="crafting-message">{{ message }}</p>
    <p class="crafting-subtitle">
      Crafting rank {{ craftingLevel }} — better rank means better odds at rare-and-up rarity.
      <span v-if="craftSpeedBonusPct">VIP speeds up your crafts by {{ craftSpeedBonusPct }}%.</span>
    </p>

    <div class="rarity-odds-strip">
      <span v-for="(tier, key) in rarityOdds" :key="key" class="rarity-odds-chip" :class="{ 'is-locked': !tier.unlocked }" :style="{ color: tier.color }">
        {{ tier.label }}: {{ tier.unlocked ? `${tier.pct}%` : `🔒 Lv.${tier.unlock_level}` }}
      </span>
    </div>

    <div v-if="queue.length" class="crafting-queue-eyebrow">CRAFTING QUEUE</div>
    <div v-if="queue.length" class="crafting-queue-grid">
      <div v-for="job in queue" :key="job.id" class="queue-card">
        <span class="queue-card__glyph">{{ job.result_item.glyph }}</span>
        <div class="queue-card__body">
          <div class="ox queue-card__name">
            {{ job.result_item.name }}
            <span class="queue-card__rarity" :style="{ color: rarityOdds[job.rarity]?.color }">{{ rarityOdds[job.rarity]?.label ?? job.rarity }}</span>
          </div>
          <div class="queue-card__status">{{ job.is_ready ? 'Ready to collect!' : `${job.seconds_remaining}s remaining` }}</div>
        </div>
        <button class="queue-card__collect-btn" :disabled="!job.is_ready" @click="collect(job)">Collect</button>
      </div>
    </div>

    <div class="recipe-grid">
      <div
        v-for="recipe in recipes"
        :key="recipe.id"
        class="recipe-card"
      >
        <div class="recipe-card__head">
          <span class="recipe-card__glyph">{{ recipe.result_item.glyph }}</span>
          <span class="ox recipe-card__name">{{ recipe.name }}</span>
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
        <div class="recipe-card__time">⏱ {{ recipe.craft_seconds }}s to craft</div>
        <button
          @click="craft(recipe)"
          :disabled="!recipe.can_craft || hasActiveJob()"
          class="recipe-card__craft-btn"
        >
          {{ !recipe.can_craft ? 'Missing Materials' : hasActiveJob() ? 'Queue busy' : 'Craft' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./CraftingPage.scss" scoped></style>
