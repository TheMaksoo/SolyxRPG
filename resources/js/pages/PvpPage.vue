<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const record = ref(null);
const rank = ref('');
const opponents = ref([]);
const history = ref([]);
const lastResult = ref(null);
const loading = ref(false);

async function load() {
  const { data } = await api.get('/pvp');
  record.value = data.record;
  rank.value = data.rank;
  opponents.value = data.opponents;
  history.value = data.history;
}

async function findMatch() {
  loading.value = true;
  try {
    const { data } = await api.post('/pvp/find-match');
    lastResult.value = data;
    await load();
  } finally {
    loading.value = false;
  }
}

async function challenge(row) {
  loading.value = true;
  try {
    const { data } = await api.post(`/pvp/challenge/${row.character.id}`);
    lastResult.value = data;
    await load();
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">⚔</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">PvP Arena</h1>
    </div>

    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:320px">
        <div
          v-if="record"
          style="background:linear-gradient(150deg,#1a1013,#151517);border:1px solid rgba(232,72,47,.25);border-radius:14px;padding:22px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:20px;align-items:center"
        >
          <div style="width:70px;height:70px;flex:none;border-radius:16px;background:rgba(232,72,47,.16);display:grid;place-items:center;font-size:34px">⚔</div>
          <div style="flex:1;min-width:160px">
            <div class="ox" style="font-size:22px;font-weight:800">{{ rank }}</div>
            <div style="font-size:13px;color:rgba(255,255,255,.5)">{{ record.rating }} rating · {{ record.wins }}W / {{ record.losses }}L</div>
            <div v-if="record.win_streak > 0" style="font-size:12px;color:#eab308;margin-top:3px">🔥 {{ record.win_streak }} win streak</div>
          </div>
          <button
            @click="findMatch"
            :disabled="loading"
            style="background:#e8482f;color:#fff;border:none;border-radius:11px;padding:14px 26px;font-size:15px;font-weight:700;cursor:pointer"
          >
            Find ranked match
          </button>
        </div>

        <div
          v-if="lastResult"
          :style="{
            marginBottom: '18px',
            background: '#151517',
            border: `1px solid ${lastResult.result === 'win' ? 'rgba(74,222,128,.3)' : 'rgba(255,107,107,.3)'}`,
            borderRadius: '12px',
            padding: '16px',
          }"
        >
          <span :style="{ fontWeight: 700, color: lastResult.result === 'win' ? '#4ade80' : '#ff6a4d' }">
            {{ lastResult.result === 'win' ? 'Victory' : 'Defeat' }} vs {{ lastResult.opponent.name }}
          </span>
          <span style="color:rgba(255,255,255,.5);margin-left:8px">{{ lastResult.rating_delta >= 0 ? '+' : '' }}{{ lastResult.rating_delta }} rating</span>
        </div>

        <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">CHALLENGE A RIVAL</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
          <div v-for="row in opponents" :key="row.character.id" style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
            <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px">
              <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:14px">{{ row.character.name }}</div>
                <div style="font-size:11px;color:rgba(255,255,255,.45);text-transform:capitalize">{{ row.character.base_class }} · Lv.{{ row.character.level }}</div>
              </div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <span style="font-size:12px;color:#eab308">{{ row.rating }} rating</span>
              <button
                @click="challenge(row)"
                :disabled="loading"
                style="background:rgba(232,72,47,.15);color:#ff8163;border:1px solid rgba(232,72,47,.3);border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer"
              >
                Challenge
              </button>
            </div>
          </div>
          <div v-if="!opponents.length" style="color:rgba(255,255,255,.35);font-size:13px">No other players yet.</div>
        </div>
      </div>

      <div style="width:280px;flex:none">
        <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">MATCH HISTORY</div>
        <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:6px 16px">
          <div
            v-for="h in history"
            :key="h.id"
            style="display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-top:1px solid rgba(255,255,255,.05)"
          >
            <div>
              <div style="font-size:13px;font-weight:600">vs {{ h.opponent.name }}</div>
              <div :style="{ fontSize: '11px', color: h.result === 'win' ? '#4ade80' : '#ff6a4d' }">{{ h.result === 'win' ? 'Victory' : 'Defeat' }}</div>
            </div>
            <span class="ox" :style="{ fontWeight: 700, color: h.result === 'win' ? '#4ade80' : '#ff6a4d' }">{{ h.rating_delta >= 0 ? '+' : '' }}{{ h.rating_delta }}</span>
          </div>
          <div v-if="!history.length" style="padding:14px 0;color:rgba(255,255,255,.35);font-size:12.5px">No matches yet.</div>
        </div>
      </div>
    </div>
  </div>
</template>
