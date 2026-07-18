<script setup>
import { ref, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';

const store = useCharacterStore();
const message = ref('');

const ATTRS = [
  { key: 'damage', label: 'Damage', desc: '+5 ATK' },
  { key: 'armor', label: 'Armor', desc: '+4 DEF' },
  { key: 'hp', label: 'Vitality', desc: '+30 HP' },
  { key: 'mp', label: 'Focus', desc: '+20 MP' },
  { key: 'crit', label: 'Crit', desc: '+2% crit' },
];

async function spend(attr) {
  message.value = '';
  try {
    await store.spendAttribute(attr);
  } catch (e) {
    message.value = e.response?.data?.message || 'No points available.';
  }
}

onMounted(() => {
  if (!store.character) store.fetch();
});
</script>

<template>
  <div v-if="store.character">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">✦</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Skills</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">
      Attribute Points — {{ store.character.attribute_points }} available
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:26px">
      <div
        v-for="a in ATTRS"
        :key="a.key"
        style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:11px;padding:14px;text-align:center"
      >
        <div class="ox" style="font-weight:700;font-size:13.5px;margin-bottom:2px">{{ a.label }}</div>
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:10px">{{ a.desc }}</div>
        <button
          @click="spend(a.key)"
          :disabled="store.character.attribute_points < 1"
          style="width:100%;padding:7px;border-radius:7px;border:none;background:#e8482f;color:#fff;font-size:12px;font-weight:700;cursor:pointer"
        >
          +1
        </button>
      </div>
    </div>

    <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">
      Unlocked Skills — {{ store.character.skill_points }} points available
    </h3>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <span
        v-for="s in store.character.skills || []"
        :key="s.id"
        style="font-size:12px;background:#151517;border:1px solid rgba(255,255,255,.08);padding:6px 12px;border-radius:20px"
      >
        {{ s.skill?.glyph }} {{ s.skill?.name }}
      </span>
      <span v-if="!store.character.skills?.length" style="font-size:12.5px;color:rgba(255,255,255,.35)">
        No skills unlocked yet — visit the Wiki's Skills tab to see what's available, then unlock from here once you have points.
      </span>
    </div>
  </div>
</template>
