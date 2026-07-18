<script setup>
import { onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
onMounted(() => store.fetch());

function statBar(value, max, color) {
  const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
  return { pct, color };
}
</script>

<template>
  <div v-if="store.loading && !store.character" style="color:rgba(255,255,255,.5)">Loading…</div>

  <div v-else-if="store.character">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">⚔</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">{{ store.character.name }}</h1>
      <span
        style="font-size:12px;color:rgba(255,255,255,.4);background:#151517;border:1px solid rgba(255,255,255,.08);padding:3px 10px;border-radius:20px;text-transform:capitalize"
        >{{ store.character.base_class }} · Lv.{{ store.character.level }}</span
      >
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px">
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px">
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:8px">HP</div>
        <div style="height:8px;background:#0e0e10;border-radius:4px;overflow:hidden;margin-bottom:6px">
          <div
            :style="{ width: statBar(store.character.hp, store.character.hp_max).pct + '%', height: '100%', background: '#22c55e' }"
          ></div>
        </div>
        <div style="font-size:12.5px;color:rgba(255,255,255,.6)">{{ store.character.hp }} / {{ store.character.hp_max }}</div>
      </div>
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px">
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:8px">MP</div>
        <div style="height:8px;background:#0e0e10;border-radius:4px;overflow:hidden;margin-bottom:6px">
          <div
            :style="{ width: statBar(store.character.mana, store.character.mana_max).pct + '%', height: '100%', background: '#38bdf8' }"
          ></div>
        </div>
        <div style="font-size:12.5px;color:rgba(255,255,255,.6)">{{ store.character.mana }} / {{ store.character.mana_max }}</div>
      </div>
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px">
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:8px">Gold / Gems</div>
        <div class="ox" style="font-size:18px;font-weight:700">{{ store.character.gold }}g · {{ store.character.gems }}◆</div>
      </div>
      <div v-if="store.stats" style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px">
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:8px">Power</div>
        <div class="ox" style="font-size:18px;font-weight:700">{{ store.stats.power }}</div>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <router-link
        to="/battle"
        style="padding:11px 20px;border-radius:10px;background:#e8482f;color:#fff;font-weight:700;font-size:13.5px"
        >⚔ Battle</router-link
      >
      <router-link
        to="/world-map"
        style="padding:11px 20px;border-radius:10px;background:#151517;border:1px solid rgba(255,255,255,.1);color:#fff;font-weight:600;font-size:13.5px"
        >🗺 World Map</router-link
      >
      <router-link
        to="/shop"
        style="padding:11px 20px;border-radius:10px;background:#151517;border:1px solid rgba(255,255,255,.1);color:#fff;font-weight:600;font-size:13.5px"
        >🛒 Shop</router-link
      >
      <router-link
        to="/daily"
        style="padding:11px 20px;border-radius:10px;background:#151517;border:1px solid rgba(255,255,255,.1);color:#fff;font-weight:600;font-size:13.5px"
        >🎁 Daily</router-link
      >
    </div>
  </div>
</template>
