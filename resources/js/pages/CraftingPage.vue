<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const recipes = ref([]);
const message = ref('');

async function load() {
  const { data } = await api.get('/crafting/recipes');
  recipes.value = data.recipes;
}

async function craft(recipe) {
  message.value = '';
  try {
    await api.post(`/crafting/${recipe.id}/craft`);
    message.value = `Crafted ${recipe.result_item.name}.`;
  } catch (e) {
    message.value = e.response?.data?.message || 'Missing materials.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🔨</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Crafting</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:14px">{{ message }}</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <div
        v-for="recipe in recipes"
        :key="recipe.id"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px"
      >
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span style="font-size:20px">{{ recipe.result_item.glyph }}</span>
          <span class="ox" style="font-weight:700;font-size:14.5px">{{ recipe.name }}</span>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:12px">
          Requires: {{ recipe.materials_json.map((m) => `${m.qty}×`).join(', ') }}
        </div>
        <button
          @click="craft(recipe)"
          style="width:100%;padding:9px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
        >
          Craft
        </button>
      </div>
    </div>
  </div>
</template>
