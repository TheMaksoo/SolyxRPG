<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import api from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useCharacterStore } from '../stores/character';
import { formatCents } from '../currency';

const auth = useAuthStore();
const characterStore = useCharacterStore();
const packs = ref({});
const removeAds = ref(null);
const message = ref('');

const autoBattle = ref({ active: false, seconds_remaining: 0, costs: {} });
const autoBattleMessage = ref('');
const gemSinks = ref([]);

const tradeSkills = ref([]);
const autoGather = ref({ active: false, seconds_remaining: 0, costs: {}, granted_minutes: {}, gems: 0 });
const autoGatherMessage = ref('');
const autoGatherSkill = ref('mining');
const autoGatherTarget = ref('');

const GATHER_SKILLS = ['mining', 'woodchopping', 'foraging', 'smelting'];

const adsVisible = computed(() => !auth.user?.ads_removed && (auth.user?.vip_tier ?? 'none') === 'none');
// Auto-Attack and Auto-Gather already have their own interactive purchase cards above — skip them here to avoid showing them twice.
const catalogSinks = computed(() => gemSinks.value.filter((cat) => !['auto_battle', 'auto_gather'].includes(cat.key)));

function formatDuration(totalSeconds) {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

async function load() {
  const { data } = await api.get('/store/gems');
  packs.value = data.packs;
  removeAds.value = data.remove_ads;
}

async function loadAutoBattle() {
  const { data } = await api.get('/auto-battle');
  autoBattle.value = data;
}

async function loadGemSinks() {
  const { data } = await api.get('/store/gem-sinks');
  gemSinks.value = data.categories;
}

function formatKey(key) {
  return key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function unlockedTargetsFor(skillKey) {
  const skill = tradeSkills.value.find((s) => s.key === skillKey);
  return skill ? skill.targets.filter((t) => t.unlocked) : [];
}

watch(autoGatherSkill, (skillKey) => {
  const targets = unlockedTargetsFor(skillKey);
  if (!targets.some((t) => t.key === autoGatherTarget.value)) {
    autoGatherTarget.value = targets[0]?.key ?? '';
  }
});

async function loadTradeSkills() {
  const { data } = await api.get('/trade-skills');
  tradeSkills.value = data.trade_skills;
  if (!autoGatherTarget.value) {
    autoGatherTarget.value = unlockedTargetsFor(autoGatherSkill.value)[0]?.key ?? '';
  }
}

async function loadAutoGather() {
  const { data } = await api.get('/auto-gather');
  autoGather.value = data;
  if (data.active) {
    autoGatherSkill.value = data.skill;
    autoGatherTarget.value = data.target;
  }
}

async function buyAutoGather(minutes) {
  try {
    const { data } = await api.post('/auto-gather/purchase', {
      skill: autoGatherSkill.value,
      target: autoGatherTarget.value,
      minutes,
    });
    autoGather.value = {
      ...autoGather.value,
      active: true,
      skill: data.skill,
      target: data.target,
      seconds_remaining: data.seconds_remaining,
      gems: data.gems,
    };
    autoGatherMessage.value = `Auto-Gather started — ${autoGather.value.granted_minutes[minutes] ?? minutes * 2} minutes added.`;
    characterStore.fetch();
  } catch (e) {
    autoGatherMessage.value = e.response?.data?.message || 'Could not start Auto-Gather.';
  }
}

async function buyAutoBattle(minutes) {
  try {
    const { data } = await api.post('/auto-battle/purchase', { minutes });
    autoBattle.value = { ...autoBattle.value, active: true, seconds_remaining: data.seconds_remaining, gems: data.gems };
    autoBattleMessage.value = `Auto-Attack started — ${minutes} minutes added.`;
    characterStore.fetch();
  } catch (e) {
    autoBattleMessage.value = e.response?.data?.message || 'Could not start auto-attack.';
  }
}

async function checkout(sku) {
  message.value = '';
  try {
    const { data } = await api.post('/store/checkout', { sku });
    window.location.href = data.checkout_url;
  } catch (e) {
    message.value = e.response?.data?.message || 'Checkout unavailable.';
  }
}

onMounted(async () => {
  load();
  loadAutoBattle();
  loadGemSinks();
  await loadTradeSkills();
  loadAutoGather();
});
</script>

<template>
  <div>
    <div class="gem-store-header">
      <div class="gem-store-header__icon">💎</div>
      <h1 class="ox gem-store-title">Gem Store</h1>
    </div>

    <p class="gem-store-intro">
      Premium currency for cosmetics, revives, and Battle Pass tiers. Gems never affect combat stats — no pay-to-win.
    </p>

    <p v-if="message" class="gem-store-error">{{ message }}</p>

    <div class="gem-packs">
      <div v-for="(pack, sku) in packs" :key="sku" class="gem-pack">
        <div class="gem-pack__icon">◆</div>
        <div class="ox gem-pack__label">{{ pack.gems }} Gems</div>
        <button @click="checkout(sku)" class="gem-pack__buy">
          {{ formatCents(pack.price_cents) }}
        </button>
      </div>
    </div>

    <div v-if="adsVisible && removeAds" class="remove-ads-card">
      <div class="remove-ads-card__info">
        <div class="remove-ads-card__icon">🚫</div>
        <div>
          <div class="remove-ads-card__title">Remove Ads</div>
          <div class="remove-ads-card__desc">One-time purchase — removes ads permanently (also included with any VIP tier).</div>
        </div>
      </div>
      <button @click="checkout('remove_ads')" class="remove-ads-card__buy">
        {{ formatCents(removeAds.price_cents) }}
      </button>
    </div>

    <div v-if="adsVisible" class="ad-free-card">
      <div class="ad-free-card__info">
        <div class="ad-free-card__icon">🎬</div>
        <div>
          <div class="ad-free-card__title">Free gems — watch a short ad</div>
          <div class="ad-free-card__desc">Not wired up yet — needs a real ad-network SDK (e.g. AdSense rewarded ads).</div>
        </div>
      </div>
      <router-link to="/vip" class="ad-free-card__cta">Go ad-free with VIP</router-link>
    </div>

    <div class="gem-store-section-eyebrow">SPEND GEMS</div>
    <div class="auto-battle-store-card">
      <div class="auto-battle-store-card__info">
        <div class="auto-battle-store-card__icon">🤖</div>
        <div>
          <div class="auto-battle-store-card__title">Auto-Attack</div>
          <div class="auto-battle-store-card__desc">
            Fights for you while you're away — attacks above 50% HP, heals at 30%.
          </div>
        </div>
      </div>
      <p v-if="autoBattleMessage" class="auto-battle-store-card__message">{{ autoBattleMessage }}</p>
      <div v-if="autoBattle.active" class="auto-battle-store-card__status">
        Active — {{ formatDuration(autoBattle.seconds_remaining) }} remaining
      </div>
      <div class="auto-battle-store-card__options">
        <button
          v-for="minutes in [15, 30, 60]"
          :key="minutes"
          class="auto-battle-store-card__option"
          :disabled="(characterStore.character?.gems ?? 0) < (autoBattle.costs[minutes] ?? 0)"
          @click="buyAutoBattle(minutes)"
        >
          {{ minutes }}m · 💎{{ autoBattle.costs[minutes] ?? '—' }}
        </button>
        <button class="auto-battle-store-card__option auto-battle-store-card__option--cash" @click="checkout('auto_battle_60')">
          60m · {{ formatCents(100) }}
        </button>
      </div>
    </div>

    <div class="auto-gather-store-card">
      <div class="auto-gather-store-card__info">
        <div class="auto-gather-store-card__icon">⛏</div>
        <div>
          <div class="auto-gather-store-card__title">Auto-Gather</div>
          <div class="auto-gather-store-card__desc">
            Gathers resources for you while you're away — same price as Auto-Attack, double the duration.
          </div>
        </div>
      </div>
      <p v-if="autoGatherMessage" class="auto-gather-store-card__message">{{ autoGatherMessage }}</p>
      <div v-if="autoGather.active" class="auto-gather-store-card__status">
        🤖 Auto-{{ autoGather.skill }} active — gathering {{ autoGather.target }} — {{ formatDuration(autoGather.seconds_remaining) }} remaining
      </div>
      <template v-else>
        <div class="auto-gather-store-card__picker">
          <select v-model="autoGatherSkill" class="auto-gather-store-card__select">
            <option v-for="key in GATHER_SKILLS" :key="key" :value="key">{{ formatKey(key) }}</option>
          </select>
          <select v-model="autoGatherTarget" class="auto-gather-store-card__select">
            <option v-for="t in unlockedTargetsFor(autoGatherSkill)" :key="t.key" :value="t.key">{{ t.label }}</option>
          </select>
        </div>
        <div class="auto-gather-store-card__options">
          <button
            v-for="minutes in [15, 30, 60]"
            :key="minutes"
            class="auto-gather-store-card__option"
            :disabled="!autoGatherTarget || (characterStore.character?.gems ?? 0) < (autoGather.costs[minutes] ?? 0)"
            @click="buyAutoGather(minutes)"
          >
            {{ autoGather.granted_minutes[minutes] ?? minutes * 2 }}m · 💎{{ autoGather.costs[minutes] ?? '—' }}
          </button>
        </div>
      </template>
    </div>

    <div class="gem-store-section-eyebrow">EVERYWHERE YOU CAN SPEND GEMS</div>
    <div class="gem-sink-grid">
      <router-link
        v-for="cat in catalogSinks"
        :key="cat.key"
        :to="cat.route"
        class="gem-sink-card"
      >
        <div class="gem-sink-card__header">
          <span class="gem-sink-card__glyph">{{ cat.glyph }}</span>
          <span class="ox gem-sink-card__label">{{ cat.label }}</span>
        </div>
        <p class="gem-sink-card__desc">{{ cat.desc }}</p>
        <div class="gem-sink-card__items">
          <span
            v-for="(item, i) in cat.items"
            :key="i"
            class="gem-sink-card__item"
            :class="{ 'gem-sink-card__item--owned': item.owned }"
          >
            {{ item.label }} <template v-if="item.owned">✔ owned</template><template v-else>· 💎{{ item.cost }}</template>
          </span>
          <span v-if="!cat.items.length" class="gem-sink-card__item gem-sink-card__item--empty">Nothing available right now</span>
        </div>
      </router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./GemStorePage.scss" scoped></style>
