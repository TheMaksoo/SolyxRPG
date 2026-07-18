<script setup>
import { ref, computed, onMounted } from 'vue';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const store = useCharacterStore();
const message = ref('');
const allSkills = ref([]);
const progressions = ref([]);

const ATTRS = [
  { key: 'damage', label: 'Damage', desc: '+5 ATK' },
  { key: 'armor', label: 'Armor', desc: '+4 DEF' },
  { key: 'hp', label: 'Vitality', desc: '+30 HP' },
  { key: 'mp', label: 'Focus', desc: '+20 MP' },
  { key: 'crit', label: 'Crit', desc: '+2% crit' },
];

const TIER_CAPS = { t20: 20, t40: 40, t60: 60 };
const TIER_COLUMN = { t20: 'spec_class', t40: 'profession', t60: 'ascension' };

async function load() {
  const [skillsRes, progRes] = await Promise.all([api.get('/skills'), api.get('/class-progressions')]);
  allSkills.value = skillsRes.data.skills;
  progressions.value = progRes.data.progressions;
  if (!store.character) await store.fetch();
}

const unlockedIds = computed(() => new Set((store.character?.skills || []).map((s) => s.skill_id)));

const branches = computed(() => {
  const groups = {};
  for (const skill of allSkills.value) {
    (groups[skill.branch] ??= []).push(skill);
  }
  return Object.entries(groups).map(([branch, skills]) => ({
    branch,
    nodes: skills
      .sort((a, b) => a.tier - b.tier)
      .map((skill, i, arr) => {
        const unlocked = unlockedIds.value.has(skill.id);
        const prevUnlocked = i === 0 || unlockedIds.value.has(arr[i - 1].id);
        const levelOk = (store.character?.level ?? 0) >= skill.level_req;
        const canUnlock = !unlocked && prevUnlocked && levelOk && (store.character?.skill_points ?? 0) > 0;

        return {
          skill,
          unlocked,
          canUnlock,
          locked: !unlocked && (!prevUnlocked || !levelOk),
          reqText: !prevUnlocked ? 'Unlock previous skill first' : `Requires level ${skill.level_req}`,
        };
      }),
  }));
});

const tiers = ['t20', 't40', 't60'];
const classPath = computed(() =>
  tiers.map((tier) => {
    const options = progressions.value.filter((p) => p.tier === tier);
    const column = TIER_COLUMN[tier];
    const chosenKey = store.character?.[column];
    const chosen = options.find((o) => o.key === chosenKey);
    const cap = TIER_CAPS[tier];

    const priorColumn = tier === 't40' ? 'spec_class' : tier === 't60' ? 'profession' : null;
    const priorChosen = !priorColumn || !!store.character?.[priorColumn];

    return {
      tier,
      heading: tier.toUpperCase(),
      cap,
      chosen,
      isChosen: !!chosen,
      isChoose: !chosen && priorChosen && (store.character?.level ?? 0) >= cap,
      isLocked: !chosen && (!priorChosen || (store.character?.level ?? 0) < cap),
      reqText: !priorChosen ? 'Choose the previous tier first' : `Reach level ${cap}`,
      options,
    };
  })
);

async function spend(attr) {
  message.value = '';
  try {
    await store.spendAttribute(attr);
  } catch (e) {
    message.value = e.response?.data?.message || 'No points available.';
  }
}

async function unlockSkill(node) {
  message.value = '';
  try {
    await store.unlockSkill(node.skill.id);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not unlock.';
  }
}

async function pickProgression(tier, option) {
  message.value = '';
  try {
    await store.chooseProfession(tier, option.key);
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not choose.';
  }
}

onMounted(load);
</script>

<template>
  <div v-if="store.character">
    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:12px">
      CLASS PATH — grow from your base class into a profession at each level cap
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:28px">
      <div v-for="t in classPath" :key="t.tier" style="background:#151517;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div style="font-size:11px;letter-spacing:.1em;color:rgba(255,255,255,.4);font-weight:600">{{ t.heading }}</div>
          <span style="font-size:10px;color:#ff8163;font-weight:700">Lv.{{ t.cap }}</span>
        </div>
        <div v-if="t.isChosen" style="display:flex;align-items:center;gap:10px">
          <div style="width:40px;height:40px;border-radius:9px;background:rgba(232,72,47,.14);display:grid;place-items:center;font-size:20px">{{ t.chosen.glyph }}</div>
          <div>
            <div class="ox" style="font-weight:700;font-size:15px">{{ t.chosen.name }}</div>
            <div style="font-size:11px;color:rgba(255,255,255,.45)">{{ t.chosen.description }}</div>
          </div>
        </div>
        <div v-else-if="t.isChoose" style="display:flex;flex-direction:column;gap:8px">
          <button
            v-for="o in t.options"
            :key="o.id"
            @click="pickProgression(t.tier, o)"
            style="text-align:left;display:flex;align-items:center;gap:9px;background:#0e0e10;border:1px solid rgba(232,72,47,.3);border-radius:9px;padding:9px 11px;cursor:pointer;color:#ededed"
          >
            <span style="font-size:18px">{{ o.glyph }}</span>
            <span><span style="font-weight:700;font-size:13px">{{ o.name }}</span><br /><span style="font-size:10px;color:rgba(255,255,255,.45)">{{ o.description }}</span></span>
          </button>
        </div>
        <div v-else style="display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.35);font-size:13px;padding:8px 0">🔒 {{ t.reqText }}</div>
      </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
      <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600">ATTRIBUTES — spend points earned on level up</div>
      <div style="background:rgba(232,72,47,.12);border:1px solid rgba(232,72,47,.3);color:#ff8163;padding:7px 14px;border-radius:9px;font-weight:700;font-size:13px">✦ {{ store.character.attribute_points }} attribute points</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:28px;max-width:920px">
      <div v-for="a in ATTRS" :key="a.key" style="background:#151517;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:15px;display:flex;align-items:center;justify-content:space-between">
        <div>
          <div style="font-weight:700;font-size:13px">{{ a.label }}</div>
          <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:2px">{{ a.desc }}</div>
        </div>
        <button
          @click="spend(a.key)"
          :disabled="store.character.attribute_points < 1"
          style="width:32px;height:32px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:16px;cursor:pointer"
        >
          +
        </button>
      </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
      <div style="font-size:12px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600">SKILL TREE — abilities cost MP to cast in battle</div>
      <div style="background:rgba(232,72,47,.12);border:1px solid rgba(232,72,47,.3);color:#ff8163;padding:9px 16px;border-radius:10px;font-weight:700">◆ {{ store.character.skill_points }} skill points</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;max-width:820px">
      <div v-for="col in branches" :key="col.branch">
        <div class="ox" style="text-align:center;font-weight:700;font-size:14px;color:#e8482f;margin-bottom:14px">{{ col.branch }}</div>
        <div style="display:flex;flex-direction:column;gap:12px">
          <button
            v-for="nd in col.nodes"
            :key="nd.skill.id"
            @click="nd.canUnlock && unlockSkill(nd)"
            :disabled="!nd.canUnlock"
            :style="{
              background: nd.unlocked ? 'rgba(232,72,47,.13)' : '#151517',
              border: `1px solid ${nd.unlocked ? '#e8482f' : 'rgba(255,255,255,.08)'}`,
              borderRadius: '11px',
              padding: '12px',
              textAlign: 'left',
              color: '#fff',
              cursor: nd.canUnlock ? 'pointer' : 'default',
            }"
          >
            <div style="font-size:24px;margin-bottom:6px">{{ nd.skill.glyph }}</div>
            <div style="font-weight:700;font-size:13px">{{ nd.skill.name }}</div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:3px">{{ nd.skill.description }}</div>
            <div
              style="font-size:11px;margin-top:6px"
              :style="{ color: nd.unlocked ? '#4ade80' : nd.canUnlock ? '#eab308' : 'rgba(255,255,255,.35)' }"
            >
              {{ nd.unlocked ? 'Unlocked' : nd.canUnlock ? 'Click to unlock' : nd.reqText }}
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
