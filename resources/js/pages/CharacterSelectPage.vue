<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useCharacterStore } from '../stores/character';

const router = useRouter();
const store = useCharacterStore();

const data = ref(null);
const loading = ref(true);
const busyId = ref(null);
const error = ref('');

const CLASS_ICON = { warrior: '⚔', mage: '✷', rogue: '🗡', ranger: '🏹' };

const nextGemTier = computed(() => (data.value ? data.value.bonus_character_slots + 1 : null));

async function load() {
  loading.value = true;
  try {
    data.value = await store.fetchSlots();
  } finally {
    loading.value = false;
  }
}

async function play(character) {
  error.value = '';
  busyId.value = character.id;
  try {
    await store.select(character.id);
    router.push('/dashboard');
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not switch character.';
  } finally {
    busyId.value = null;
  }
}

async function unlock() {
  error.value = '';
  const payer = data.value.slots.map((s) => s.character).find(Boolean);
  if (!payer) { error.value = 'Create a character first.'; return; }
  busyId.value = 'unlock';
  try {
    await store.unlockSlot(payer.id);
    await load();
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not unlock slot.';
  } finally {
    busyId.value = null;
  }
}

onMounted(load);
</script>

<template>
  <div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:40px 24px;gap:26px">
    <div style="text-align:center">
      <h1 class="ox" style="font-size:26px;font-weight:800;margin:0 0 6px">Your Characters</h1>
      <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0">
        Choose a character to play, or open a new slot.
      </p>
    </div>

    <p v-if="error" style="color:#ff6a4d;font-size:12.5px;margin:0">{{ error }}</p>

    <div v-if="loading" style="color:rgba(255,255,255,.4);font-size:13px">Loading…</div>

    <div v-else style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;max-width:920px;width:100%">
      <div
        v-for="slot in data.slots"
        :key="slot.number"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px;min-height:170px;display:flex;flex-direction:column;justify-content:space-between"
      >
        <!-- filled -->
        <template v-if="slot.character">
          <div>
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
              <div style="font-size:26px">{{ CLASS_ICON[slot.character.base_class] }}</div>
              <span
                v-if="slot.character.id === data.active_character_id"
                style="font-size:10px;font-weight:700;letter-spacing:.04em;color:#4ade80;background:rgba(74,222,128,.12);padding:3px 7px;border-radius:6px"
                >ACTIVE</span
              >
            </div>
            <div class="ox" style="font-weight:700;font-size:15px;margin-top:8px">{{ slot.character.name }}</div>
            <div style="font-size:11.5px;color:rgba(255,255,255,.45);margin-top:2px;text-transform:capitalize">
              Lv.{{ slot.character.level }} {{ slot.character.base_class }}
            </div>
            <div style="display:flex;gap:10px;margin-top:10px;font-size:11px;color:rgba(255,255,255,.5)">
              <span>♥ {{ slot.character.hp_max }}</span>
              <span>⚔ {{ slot.character.base_atk }}</span>
              <span>✷ {{ slot.character.mana_max }}</span>
            </div>
          </div>
          <button
            @click="play(slot.character)"
            :disabled="busyId === slot.character.id"
            style="margin-top:14px;padding:9px;border-radius:9px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:13px;cursor:pointer"
          >
            {{ busyId === slot.character.id ? 'Loading…' : slot.character.id === data.active_character_id ? 'Continue' : 'Play' }}
          </button>
        </template>

        <!-- unlocked, empty -->
        <template v-else-if="slot.unlocked">
          <router-link
            to="/character/create"
            style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:rgba(255,255,255,.5);text-decoration:none;border:1px dashed rgba(255,255,255,.15);border-radius:9px"
          >
            <div style="font-size:22px">+</div>
            <div style="font-size:12.5px;font-weight:600">Create Character</div>
          </router-link>
        </template>

        <!-- locked: gems -->
        <template v-else-if="slot.requirement.type === 'gems'">
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:rgba(255,255,255,.35)">
            <div style="font-size:20px">🔒</div>
            <div style="font-size:12px">Slot {{ slot.number }}</div>
            <div style="font-size:11px">{{ slot.requirement.cost }}◆ gems</div>
          </div>
          <button
            v-if="slot.requirement.tier === nextGemTier"
            @click="unlock"
            :disabled="busyId === 'unlock'"
            style="margin-top:10px;padding:8px;border-radius:9px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-weight:600;font-size:12px;cursor:pointer"
          >
            {{ busyId === 'unlock' ? 'Unlocking…' : `Unlock for ${slot.requirement.cost}◆` }}
          </button>
          <div v-else style="margin-top:10px;text-align:center;font-size:10.5px;color:rgba(255,255,255,.3)">
            Unlock earlier slots first
          </div>
        </template>

        <!-- locked: vip -->
        <template v-else>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:rgba(255,255,255,.35)">
            <div style="font-size:20px">🔒</div>
            <div style="font-size:12px">Slot {{ slot.number }}</div>
            <div style="font-size:11px;text-transform:capitalize">{{ slot.requirement.tier }} VIP</div>
          </div>
          <router-link
            to="/vip"
            style="margin-top:10px;padding:8px;border-radius:9px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-weight:600;font-size:12px;text-align:center;text-decoration:none"
          >
            View VIP
          </router-link>
        </template>
      </div>
    </div>
  </div>
</template>
