<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const characterStore = useCharacterStore();
const enemies = ref([]);
const battle = ref(null);
const result = ref(null);
const loading = ref(false);
const error = ref('');

const monster = computed(() => battle.value?.monster ?? null);
const playerHpMax = computed(() => characterStore.stats?.eff_hp_max ?? battle.value?.character_hp ?? 1);
const hpPct = (hp, max) => (max > 0 ? Math.max(0, Math.min(100, Math.round((hp / max) * 100))) : 0);

async function loadEnemies() {
  const { data } = await api.get('/battle/enemies');
  enemies.value = data.enemies;
}

async function startBattle(monsterId) {
  error.value = '';
  loading.value = true;
  try {
    const { data } = await api.post('/battle/start', { monster_id: monsterId });
    battle.value = data.battle;
    result.value = null;
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not start battle.';
  } finally {
    loading.value = false;
  }
}

async function act(type) {
  if (!battle.value || loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api.post(`/battle/${battle.value.id}/action`, { type });
    battle.value = data.battle;
    result.value = data.result;
  } catch (e) {
    error.value = e.response?.data?.message || 'Action failed.';
  } finally {
    loading.value = false;
  }
}

function newFight() {
  battle.value = null;
  result.value = null;
  loadEnemies();
}

onMounted(() => {
  loadEnemies();
  if (!characterStore.stats) characterStore.fetch();
});
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">⚔</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Battle</h1>
    </div>

    <p v-if="error" style="color:#ff6a4d;font-size:13px;margin-bottom:14px">{{ error }}</p>

    <!-- Enemy select -->
    <div v-if="!battle">
      <div v-if="enemies.length === 0" style="color:rgba(255,255,255,.4);font-size:13.5px">
        No enemies available for your level yet — try traveling to a zone that matches your level in
        <router-link to="/world-map" style="color:#ff6a4d">World Map</router-link>.
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
        <button
          v-for="e in enemies"
          :key="e.id"
          @click="startBattle(e.id)"
          :disabled="loading"
          style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:16px;text-align:left;cursor:pointer;color:#fff"
        >
          <div style="font-size:26px;margin-bottom:8px">{{ e.glyph }}</div>
          <div class="ox" style="font-weight:700;font-size:15px">{{ e.name }}</div>
          <div style="font-size:11.5px;color:rgba(255,255,255,.45);margin-top:4px">
            HP {{ e.hp }} · ATK {{ e.atk }} · Lv.{{ e.min_level }}+
          </div>
        </button>
      </div>
    </div>

    <!-- Fight view -->
    <div v-else-if="battle.status === 'active'" style="max-width:560px">
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:20px;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <span class="ox" style="font-weight:700">{{ monster.name }}</span>
          <span style="font-size:12px;color:rgba(255,255,255,.5)">{{ battle.monster_hp }} / {{ monster.hp }}</span>
        </div>
        <div style="height:10px;background:#0e0e10;border-radius:5px;overflow:hidden">
          <div :style="{ width: hpPct(battle.monster_hp, monster.hp) + '%', height: '100%', background: '#e8482f' }"></div>
        </div>
      </div>

      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:20px;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <span class="ox" style="font-weight:700">You</span>
        </div>
        <div style="height:10px;background:#0e0e10;border-radius:5px;overflow:hidden">
          <div :style="{ width: hpPct(battle.character_hp, playerHpMax) + '%', height: '100%', background: '#22c55e' }"></div>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,.5);margin-top:6px">{{ battle.character_hp }} HP</div>
      </div>

      <div style="display:flex;gap:10px;margin-bottom:16px">
        <button
          @click="act('attack')"
          :disabled="loading"
          style="flex:1;padding:12px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer"
        >
          Attack
        </button>
        <button
          @click="act('item')"
          :disabled="loading"
          style="flex:1;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-weight:600;cursor:pointer"
        >
          Use Item
        </button>
      </div>

      <div style="background:#0e0e10;border-radius:10px;padding:12px 14px;font-size:12.5px;color:rgba(255,255,255,.55);max-height:140px;overflow-y:auto">
        <div v-for="(line, i) in [...(battle.log_json || [])].reverse()" :key="i">{{ line }}</div>
      </div>
    </div>

    <!-- Result view -->
    <div v-else style="max-width:480px">
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:24px;text-align:center">
        <div style="font-size:32px;margin-bottom:10px">{{ battle.status === 'won' ? '🏆' : '💀' }}</div>
        <h2 class="ox" style="margin:0 0 10px">{{ battle.status === 'won' ? 'Victory!' : 'Defeated' }}</h2>
        <div v-if="result?.outcome === 'won'" style="font-size:14px;color:rgba(255,255,255,.7);margin-bottom:16px">
          +{{ result.gold }}g · +{{ result.xp }}xp<span v-if="result.gems"> · +{{ result.gems }}◆</span>
          <div v-if="result.leveled_up" style="color:#eab308;margin-top:6px">Level up! +{{ result.leveled_up * 3 }} attribute pts, +{{ result.leveled_up }} skill pts</div>
        </div>
        <div v-else style="font-size:14px;color:rgba(255,255,255,.7);margin-bottom:16px">Revived at 50% HP.</div>
        <button
          @click="newFight"
          style="padding:11px 24px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer"
        >
          Fight again
        </button>
      </div>
    </div>
  </div>
</template>
