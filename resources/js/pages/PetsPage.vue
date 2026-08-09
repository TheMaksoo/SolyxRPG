<script setup>
import { ref, onMounted, computed } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { gradeMeta } from '../utils/grade';

const characterStore = useCharacterStore();

const pets = ref([]);
const message = ref('');
const maxActiveSlots = ref(1);
const activeCount = ref(0);

const petRows = computed(() => pets.value);

async function load() {
  const { data } = await api.get('/pets');
  pets.value = data.pets;
  maxActiveSlots.value = data.max_active_slots;
  activeCount.value = data.active_count;
}

// Tamed companions — a separate pool from the catalog pets above (own roster/active caps, see
// User::tameRosterCap/activeTameSlots), fought as real combatants rather than a passive % bonus.
const tamedCompanions = ref([]);
const tameRosterCap = ref(3);
const activeTameSlots = ref(1);
const activeTameCount = ref(0);
const tameLog = ref([]);
const releasingId = ref(null);
const feedingId = ref(null);
const renameCostGems = ref(300);
const renamingId = ref(null);
const renameDraft = ref('');
const renameSavingId = ref(null);
const revivingId = ref(null);
const reviveAttemptsMax = ref(2);

const ownedPetFood = computed(() =>
  (characterStore.character?.inventory ?? []).find((i) => i.item?.type === 'pet_food' && i.qty > 0)
);

// The strongest revive potion currently owned — highest revive_chance_pct wins, so a Revive attempt
// always uses whatever gives a downed companion the best odds rather than making the player pick.
const bestRevivePotion = computed(() =>
  (characterStore.character?.inventory ?? [])
    .filter((i) => i.item?.type === 'pet_revive_potion' && i.qty > 0)
    .sort((a, b) => (b.item.stat_json?.revive_chance_pct ?? 0) - (a.item.stat_json?.revive_chance_pct ?? 0))[0]
);

const downedCompanions = computed(() => tamedCompanions.value.filter((c) => c.is_downed));
const livingCompanions = computed(() => tamedCompanions.value.filter((c) => !c.is_downed));

async function loadTamed() {
  const { data } = await api.get('/companions');
  tamedCompanions.value = data.companions;
  tameRosterCap.value = data.roster_cap;
  activeTameSlots.value = data.active_slots;
  activeTameCount.value = data.active_count;
  renameCostGems.value = data.rename_cost_gems;
  reviveAttemptsMax.value = data.revive_attempts_max;
  tameLog.value = data.log;
}

async function reviveTamed(companion) {
  if (!bestRevivePotion.value) return;
  revivingId.value = companion.id;
  message.value = '';
  try {
    const { data } = await api.post(`/companions/${companion.id}/revive`, { item_id: bestRevivePotion.value.item_id });
    message.value = data.revived
      ? `${companion.name} was revived!`
      : data.gone
        ? data.message
        : `The revive attempt failed — ${reviveAttemptsMax.value - companion.revive_attempts_used - 1} attempt(s) left.`;
    await loadTamed();
    await characterStore.fetch();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not revive that companion.';
  } finally {
    revivingId.value = null;
  }
}

function startRename(companion) {
  renamingId.value = companion.id;
  renameDraft.value = companion.name;
}

function cancelRename() {
  renamingId.value = null;
  renameDraft.value = '';
}

async function renameTamed(companion) {
  const name = renameDraft.value.trim();
  if (!name || name === companion.name) return;
  renameSavingId.value = companion.id;
  message.value = '';
  try {
    await api.post(`/companions/${companion.id}/rename`, { name });
    await characterStore.fetch();
    await loadTamed();
    renamingId.value = null;
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not rename that companion.';
  } finally {
    renameSavingId.value = null;
  }
}

async function activateTamed(companion) {
  message.value = '';
  try {
    await api.post(`/companions/${companion.id}/activate`);
    await loadTamed();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not toggle that companion.';
  }
}

async function releaseTamed(companion) {
  if (!confirm(`Set ${companion.name} free? This can't be undone.`)) return;
  releasingId.value = companion.id;
  message.value = '';
  try {
    await api.post(`/companions/${companion.id}/release`);
    await loadTamed();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not release that companion.';
  } finally {
    releasingId.value = null;
  }
}

async function feedTamed(companion) {
  if (!ownedPetFood.value) return;
  feedingId.value = companion.id;
  message.value = '';
  try {
    await api.post(`/companions/${companion.id}/feed`, { item_id: ownedPetFood.value.item_id });
    await loadTamed();
    await characterStore.fetch();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not feed that companion.';
  } finally {
    feedingId.value = null;
  }
}

async function unlock(row) {
  message.value = '';
  try {
    await api.post(`/pets/${row.pet.id}/unlock`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Not enough gems.';
  }
}

async function activate(row) {
  message.value = '';
  try {
    await api.post(`/pets/${row.pet.id}/activate`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not toggle that companion.';
  }
}

const rankingUp = ref(null);
async function rankUp(row) {
  if (rankingUp.value) return;
  message.value = '';
  rankingUp.value = row.pet.id;
  try {
    await api.post(`/pets/${row.pet.id}/rank-up`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not rank up that companion.';
  } finally {
    rankingUp.value = null;
  }
}

onMounted(() => {
  load();
  loadTamed();
  if (!characterStore.character?.inventory) characterStore.fetch();
});
</script>

<template>
  <div>
    <div class="pets-header">
      <div class="pets-header__icon">🐾</div>
      <h1 class="ox pets-title">Companions</h1>
      <span class="pets-header__slots">{{ activeCount }} / {{ maxActiveSlots }} active</span>
    </div>

    <p v-if="message" class="pets-message">{{ message }}</p>

    <div class="pets-grid">
      <div
        v-for="row in petRows"
        :key="row.pet.id"
        class="pet-card"
        :class="{ 'pet-card--active': row.active }"
      >
        <div class="pet-card__glyph">{{ row.pet.glyph }}</div>
        <div class="ox pet-card__name">{{ row.pet.name }}</div>
        <div class="pet-card__desc">{{ row.pet.description }}</div>
        <div class="pet-card__bonuses">
          <span v-for="b in row.bonuses" :key="b.label" class="pet-card__bonus-chip">+{{ b.pct }}% {{ b.label }}</span>
        </div>
        <div v-if="row.owned" class="pet-card__level-block">
          <div class="pet-card__level-row">
            <span>Rank {{ row.rank }} · Lv.{{ row.level }}</span>
            <span v-if="!row.can_rank_up">{{ row.xp }} / {{ row.xp_needed }} xp</span>
            <span v-else-if="row.rank >= row.max_rank">MAX RANK</span>
            <span v-else>READY TO RANK UP</span>
          </div>
          <div class="pet-card__xp-track">
            <div
              class="pet-card__xp-fill"
              :style="{ width: (row.can_rank_up ? 100 : Math.round((row.xp / row.xp_needed) * 100)) + '%' }"
            ></div>
          </div>
        </div>
        <button
          v-if="!row.owned"
          @click="unlock(row)"
          class="pet-card__btn--unlock"
        >
          <template v-if="row.pet.unlock_gold">Unlock — {{ row.pet.unlock_gold }}🪙</template>
          <template v-else>Unlock — {{ row.pet.unlock_gems }}◆</template>
        </button>
        <button
          v-else-if="row.can_rank_up"
          @click="rankUp(row)"
          :disabled="rankingUp === row.pet.id"
          class="pet-card__btn--rank-up"
        >
          {{ rankingUp === row.pet.id ? 'Ranking up…' : `Rank Up — ${row.rank_up_cost.gold}🪙${row.rank_up_cost.gems ? ` + ${row.rank_up_cost.gems}◆` : ''}` }}
        </button>
        <button
          v-else-if="!row.active"
          @click="activate(row)"
          class="pet-card__btn--activate"
        >
          Activate
        </button>
        <button v-else @click="activate(row)" class="pet-card__status--active">
          ✔ Active — click to bench
        </button>
      </div>
    </div>

    <div class="pets-header tame-header">
      <div class="pets-header__icon">🐾</div>
      <h1 class="ox pets-title">Tamed Companions</h1>
      <span class="pets-header__slots">
        {{ activeTameCount }} / {{ activeTameSlots }} active · {{ tamedCompanions.length }} / {{ tameRosterCap }} roster
      </span>
    </div>

    <p class="tame-hint">
      Weaken a wild monster to ≤10% HP in a 1v1 fight, then try to tame it — captured companions fight
      alongside you from then on. More active slots unlock every 100 levels; release one to make room
      for a new capture once your roster is full.
    </p>

    <div v-if="!tamedCompanions.length" class="tame-empty-hint">No tamed companions yet.</div>

    <div v-else-if="livingCompanions.length" class="pets-grid">
      <div
        v-for="c in livingCompanions"
        :key="c.id"
        class="pet-card"
        :class="{ 'pet-card--active': c.active }"
      >
        <div class="pet-card__glyph">{{ c.glyph }}</div>

        <div v-if="renamingId === c.id" class="pet-card__rename-row">
          <input
            v-model="renameDraft"
            type="text"
            maxlength="30"
            aria-label="Companion name"
            class="pet-card__rename-input"
            @keyup.enter="renameTamed(c)"
            @keyup.esc="cancelRename"
          />
          <button
            @click="renameTamed(c)"
            :disabled="renameSavingId === c.id || !renameDraft.trim() || renameDraft.trim() === c.name || (characterStore.character?.gems ?? 0) < renameCostGems"
            class="pet-card__rename-btn"
          >{{ renameSavingId === c.id ? '…' : `💎${renameCostGems}` }}</button>
          <button @click="cancelRename" class="pet-card__rename-btn pet-card__rename-btn--cancel">✕</button>
        </div>
        <div v-else class="ox pet-card__name">
          {{ c.name }}
          <span v-if="c.is_elite" class="pet-card__bonus-chip">⭐ Elite</span>
          <span
            v-if="c.grade"
            class="pet-card__grade"
            :style="{ color: gradeMeta(c.grade).color, borderColor: gradeMeta(c.grade).color }"
          >{{ gradeMeta(c.grade).label }}</span>
          <button class="pet-card__rename-toggle" title="Rename" @click="startRename(c)">✎</button>
        </div>
        <div class="pet-card__desc">ATK {{ c.effective_atk }} · DEF {{ c.effective_def }} · Max HP {{ c.effective_hp_max }}</div>

        <div class="pet-card__level-block">
          <div class="pet-card__level-row">
            <span>Lv.{{ c.level }} / {{ c.max_level }}</span>
            <span v-if="c.level >= c.max_level">MAX LEVEL</span>
            <span v-else>{{ c.xp }} / {{ c.xp_needed }} xp</span>
          </div>
          <div class="pet-card__xp-track">
            <div
              class="pet-card__xp-fill"
              :style="{ width: (c.level >= c.max_level ? 100 : Math.round((c.xp / c.xp_needed) * 100)) + '%' }"
            ></div>
          </div>
        </div>

        <div class="pet-card__level-block">
          <div class="pet-card__level-row">
            <span>HP</span><span>{{ c.current_hp }} / {{ c.effective_hp_max }}</span>
          </div>
          <div class="pet-card__hp-track">
            <div
              class="pet-card__hp-fill"
              :style="{ width: Math.round((c.current_hp / c.effective_hp_max) * 100) + '%' }"
            ></div>
          </div>
        </div>

        <button
          @click="feedTamed(c)"
          :disabled="!ownedPetFood || feedingId === c.id || c.level >= c.max_level"
          class="pet-card__btn--activate"
        >
          <template v-if="feedingId === c.id">Feeding…</template>
          <template v-else-if="c.level >= c.max_level">MAX LEVEL</template>
          <template v-else-if="ownedPetFood">🍖 Feed (own {{ ownedPetFood.qty }})</template>
          <template v-else>No Pet Food</template>
        </button>
        <button v-if="!c.active" @click="activateTamed(c)" class="pet-card__btn--activate">
          Activate
        </button>
        <button v-else @click="activateTamed(c)" class="pet-card__status--active">
          ✔ Active — click to bench
        </button>
        <button
          @click="releaseTamed(c)"
          :disabled="releasingId === c.id"
          class="pet-card__btn--release"
        >
          {{ releasingId === c.id ? 'Releasing…' : 'Set Free' }}
        </button>
      </div>
    </div>

    <div v-if="downedCompanions.length" class="tame-header tame-header--downed">
      <div class="pets-header__icon">💀</div>
      <h1 class="ox pets-title">Downed Companions</h1>
    </div>

    <p v-if="downedCompanions.length" class="tame-hint">
      A downed companion can be revived with a Pet Revive Potion — each attempt spends one potion
      whether it works or not, and you get {{ reviveAttemptsMax }} attempts per death before it's gone
      for good. The strongest potion you own is used automatically.
    </p>

    <div v-if="downedCompanions.length" class="pets-grid">
      <div v-for="c in downedCompanions" :key="c.id" class="pet-card pet-card--downed">
        <div class="pet-card__glyph">{{ c.glyph }}</div>
        <div class="ox pet-card__name">
          {{ c.name }}
          <span v-if="c.is_elite" class="pet-card__bonus-chip">⭐ Elite</span>
        </div>
        <div class="pet-card__desc">Lv.{{ c.level }} · {{ c.revive_attempts_used }} / {{ reviveAttemptsMax }} attempts used</div>
        <button
          @click="reviveTamed(c)"
          :disabled="!bestRevivePotion || revivingId === c.id"
          class="pet-card__btn--activate"
        >
          <template v-if="revivingId === c.id">Reviving…</template>
          <template v-else-if="bestRevivePotion">🧿 Revive ({{ bestRevivePotion.item.stat_json?.revive_chance_pct }}% · own {{ bestRevivePotion.qty }})</template>
          <template v-else>No Revive Potion</template>
        </button>
        <button
          @click="releaseTamed(c)"
          :disabled="releasingId === c.id"
          class="pet-card__btn--release"
        >
          {{ releasingId === c.id ? 'Releasing…' : 'Give Up' }}
        </button>
      </div>
    </div>

    <div v-if="tameLog.length" class="tame-log">
      <div class="ox tame-log__title">Companion History</div>
      <div v-for="(entry, i) in tameLog" :key="i" class="tame-log__row">
        <span class="tame-log__name">{{ entry.glyph }} {{ entry.name }}</span>
        <span class="tame-log__event" :class="`tame-log__event--${entry.event}`">{{ entry.event }}</span>
        <span class="tame-log__level">Lv.{{ entry.level }}</span>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./PetsPage.scss" scoped></style>
