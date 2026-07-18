<script setup>
import { ref, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';
import AdBanner from '../components/AdBanner.vue';

const store = useCharacterStore();
const daily = ref(null);
const battlePass = ref(null);
const leaders = ref([]);
const quests = ref([]);
const announcements = ref([]);

async function loadRail() {
  const [dailyRes, passRes, lbRes, questRes, annRes] = await Promise.all([
    api.get('/daily'),
    api.get('/battlepass'),
    api.get('/leaderboard'),
    api.get('/quests'),
    api.get('/announcements'),
  ]);
  daily.value = dailyRes.data;
  battlePass.value = passRes.data.battle_pass;
  leaders.value = lbRes.data.leaderboard.slice(0, 5);
  quests.value = questRes.data.quests.filter((q) => !q.completed).slice(0, 3);
  announcements.value = annRes.data.announcements.slice(0, 3);
}

async function claimDaily() {
  await api.post('/daily/claim');
  const { data } = await api.get('/daily');
  daily.value = data;
}

onMounted(() => {
  store.fetch();
  loadRail();
});
</script>

<template>
  <div v-if="store.loading && !store.character" style="color:rgba(255,255,255,.5)">Loading…</div>

  <div v-else-if="store.character" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
    <div style="flex:1;min-width:320px">
      <!-- Daily reward banner -->
      <div
        v-if="daily"
        style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:linear-gradient(100deg,rgba(232,72,47,.16),rgba(232,72,47,.03));border:1px solid rgba(232,72,47,.25);border-radius:12px;padding:14px 18px;margin-bottom:18px"
      >
        <div style="display:flex;align-items:center;gap:12px">
          <div style="font-size:24px">🎁</div>
          <div>
            <div style="font-weight:700;font-size:14px">Daily reward — Day {{ daily.streak }} streak</div>
            <div style="font-size:12px;color:rgba(255,255,255,.5)">Log in every day to keep your streak going</div>
          </div>
        </div>
        <button
          @click="claimDaily"
          :disabled="!daily.can_claim"
          :style="{
            background: daily.can_claim ? '#e8482f' : 'rgba(255,255,255,.08)',
            color: '#fff',
            border: 'none',
            borderRadius: '9px',
            padding: '10px 18px',
            fontWeight: 700,
            fontSize: '13px',
            cursor: daily.can_claim ? 'pointer' : 'not-allowed',
          }"
        >
          {{ daily.can_claim ? 'Claim' : 'Claimed' }}
        </button>
      </div>

      <!-- Zone card -->
      <router-link
        to="/world-map"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;display:flex;gap:16px;margin-bottom:16px;color:inherit"
      >
        <div
          style="width:80px;height:80px;flex:none;border-radius:10px;background:repeating-linear-gradient(45deg,#16241a,#16241a 8px,#132018 8px,#132018 16px);display:grid;place-items:center;font-size:30px"
        >
          {{ store.character.zone?.glyph ?? '🗺' }}
        </div>
        <div style="flex:1;min-width:0">
          <div class="ox" style="font-size:20px;font-weight:700">{{ store.character.zone?.name ?? 'No zone selected' }}</div>
          <div style="font-size:13px;color:rgba(255,255,255,.5);margin:4px 0 8px">
            {{ store.character.zone ? `Danger: ${store.character.zone.danger}` : 'Pick a zone in World Map to start fighting' }}
          </div>
          <span
            v-if="store.character.zone"
            style="font-size:12px;background:rgba(34,197,94,.13);color:#4ade80;padding:4px 10px;border-radius:6px;font-weight:600"
            >Recommended Lv.{{ store.character.zone.min_level }}+</span
          >
        </div>
      </router-link>

      <!-- Stat tiles -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px">
        <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;text-align:center">
          <div class="ox" style="font-size:30px;font-weight:800;color:#e8482f">{{ store.character.level }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45)">Level</div>
        </div>
        <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;text-align:center">
          <div class="ox" style="font-size:30px;font-weight:800;color:#eab308">{{ store.character.gold }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45)">Gold</div>
        </div>
        <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;text-align:center">
          <div class="ox" style="font-size:22px;font-weight:800;color:#22c55e">{{ store.character.hp }}/{{ store.character.hp_max }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45)">HP</div>
        </div>
        <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;text-align:center">
          <div class="ox" style="font-size:22px;font-weight:800;color:#38bdf8">{{ store.character.mana }}/{{ store.character.mana_max }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45)">Mana</div>
        </div>
      </div>

      <!-- Quick actions -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">
        <router-link
          to="/battle"
          style="background:#e8482f;color:#fff;border:none;border-radius:11px;padding:15px;font-size:15px;font-weight:700;text-align:center"
          >⚔ Fight Monster</router-link
        >
        <router-link
          to="/quests"
          style="background:#151517;color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:11px;padding:15px;font-size:15px;font-weight:600;text-align:center"
          >❖ Quests</router-link
        >
        <router-link
          to="/shop"
          style="background:#151517;color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:11px;padding:15px;font-size:15px;font-weight:600;text-align:center"
          >◉ Shop</router-link
        >
      </div>

      <!-- Equipped -->
      <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">EQUIPPED</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:8px">
        <div
          v-for="row in (store.character.inventory || []).filter((i) => i.equipped)"
          :key="row.id"
          style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:11px;padding:15px 16px"
        >
          <div style="font-weight:700;font-size:14px">{{ row.item.glyph }} {{ row.item.name }}</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45);margin-top:3px;text-transform:capitalize">{{ row.item.type }}</div>
        </div>
        <div
          v-if="!(store.character.inventory || []).some((i) => i.equipped)"
          style="color:rgba(255,255,255,.35);font-size:13px"
        >
          Nothing equipped — visit the <router-link to="/inventory" style="color:#ff6a4d">Inventory</router-link>.
        </div>
      </div>

      <AdBanner variant="inline" />
    </div>

    <!-- Right rail -->
    <div style="width:290px;flex:none;display:flex;flex-direction:column;gap:18px">
      <div v-if="battlePass" style="background:linear-gradient(160deg,#1a1013,#151517);border:1px solid rgba(232,72,47,.2);border-radius:12px;padding:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div class="ox" style="font-weight:700;font-size:14px">Ashfall Pass</div>
          <span style="font-size:11px;color:#ff8163">Tier {{ battlePass.tier }}</span>
        </div>
        <router-link
          to="/battle-pass"
          style="display:block;text-align:center;width:100%;box-sizing:border-box;background:rgba(232,72,47,.15);color:#ff8163;border:1px solid rgba(232,72,47,.3);border-radius:9px;padding:9px;font-size:12px;font-weight:600"
          >View Battle Pass</router-link
        >
      </div>

      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
        <div style="font-size:11px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">LEADERBOARD</div>
        <div v-for="l in leaders" :key="l.character_id" style="display:flex;align-items:center;justify-content:space-between;padding:7px 0">
          <div style="display:flex;align-items:center;gap:11px">
            <span class="ox" style="width:16px;font-weight:700;color:rgba(255,255,255,.4)">{{ l.rank }}</span>
            <span style="font-size:13px">{{ l.name }}</span>
          </div>
          <span style="font-size:12px;color:rgba(255,255,255,.4)">Lv.{{ l.level }}</span>
        </div>
        <div v-if="!leaders.length" style="font-size:12.5px;color:rgba(255,255,255,.35)">No ranked characters yet.</div>
      </div>

      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
        <div style="font-size:11px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">ACTIVE QUESTS</div>
        <div v-for="row in quests" :key="row.quest.id" style="margin-bottom:14px">
          <div style="font-weight:600;font-size:13px;margin-bottom:6px">{{ row.quest.name }}</div>
          <div style="height:5px;background:#1e1e22;border-radius:4px;overflow:hidden;margin-bottom:4px">
            <div
              :style="{
                height: '100%',
                width: Math.min(100, Math.round((row.progress / (row.quest.goal_json.target ?? 1)) * 100)) + '%',
                background: '#e8482f',
              }"
            ></div>
          </div>
          <div style="font-size:11px;color:rgba(255,255,255,.4)">{{ row.progress }} / {{ row.quest.goal_json.target ?? 1 }}</div>
        </div>
        <div v-if="!quests.length" style="font-size:12.5px;color:rgba(255,255,255,.35)">No active quests.</div>
      </div>

      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px">
        <div style="font-size:11px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">ANNOUNCEMENTS</div>
        <div
          v-for="an in announcements"
          :key="an.id"
          style="display:flex;gap:10px;padding:8px 0;border-top:1px solid rgba(255,255,255,.05)"
        >
          <div style="width:30px;height:30px;flex:none;border-radius:8px;background:rgba(232,72,47,.14);display:grid;place-items:center;font-size:15px">📣</div>
          <div style="min-width:0">
            <div style="font-size:12.5px;font-weight:600">{{ an.body }}</div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:1px">{{ an.gm?.name }}</div>
          </div>
        </div>
        <div v-if="!announcements.length" style="font-size:12.5px;color:rgba(255,255,255,.35)">No announcements yet.</div>
      </div>
    </div>
  </div>
</template>
