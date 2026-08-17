<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../api/client';
import { gradeMeta, GRADE_META } from '../utils/grade';
import { dangerMeta } from '../utils/danger';
import { difficultyMeta } from '../utils/difficulty';
import { RARITY_COLORS, RARITY_LABELS } from '../rarity';

const TYPE_META = {
  monsters: { label: 'Monsters', glyph: '🐹' },
  items: { label: 'Items', glyph: '⚔' },
  skills: { label: 'Skills', glyph: '✨' },
  zones: { label: 'Zones', glyph: '🗺' },
  dungeons: { label: 'Dungeons', glyph: '🏰' },
};

const STATUS_LABEL = { open: 'Needs art', pending: 'In review', rejected: 'Changes requested', approved: 'Live' };
const GROUP_BY_OPTIONS = [
  { key: 'type', label: 'Type' },
  { key: 'zone', label: 'Place' },
  { key: 'level', label: 'Level' },
];

const tab = ref('board');
const typeFilter = ref('all');
const groupBy = ref('type');
const hideDone = ref(false);
const slots = ref([]);
const submissions = ref([]);
const loading = ref(false);

async function loadBoard() {
  loading.value = true;
  try {
    const { data } = await api.get('/gm/art/board');
    slots.value = data.slots;
  } finally {
    loading.value = false;
  }
}

async function loadMine() {
  const { data } = await api.get('/gm/art/mine');
  submissions.value = data.submissions;
}

function switchTab(name) {
  tab.value = name;
  if (name === 'board') loadBoard();
}

onMounted(() => {
  loadBoard();
  loadMine();
});

// Approved/in-review/changes-requested tallies across this artist's own submission history — shown as
// stat tiles up top, same shape as the GM Console's Art Review coverage cards, so the two pages read as
// one pipeline instead of two unrelated tools.
const myStats = computed(() => {
  const counts = { approved: 0, pending: 0, rejected: 0 };
  submissions.value.forEach((s) => { counts[s.status] = (counts[s.status] ?? 0) + 1; });
  return counts;
});

const filteredSlots = computed(() => {
  let rows = typeFilter.value === 'all' ? slots.value : slots.value.filter((s) => s.type === typeFilter.value);
  if (hideDone.value) rows = rows.filter((s) => !s.done);
  return rows;
});

function levelBucket(level) {
  if (level == null) return { key: 'none', label: 'No level', sort: -1 };
  const start = Math.floor((level - 1) / 25) * 25 + 1;
  return { key: `lv${start}`, label: `Lv ${start}–${start + 24}`, sort: start };
}

const groupedSlots = computed(() => {
  const rows = filteredSlots.value;

  if (groupBy.value === 'zone') {
    const map = new Map();
    rows.forEach((s) => {
      const key = s.zone_key || 'unscoped';
      if (!map.has(key)) {
        // Tutorial content comes before any real zone (sort -1), unscoped (items/skills/dungeons — no
        // zone at all) goes after every real zone (sort Infinity) rather than being alphabetized in
        // among them, so the low-to-high order actually matches in-game progression order.
        let sort = s.zone_min_level ?? Infinity;
        if (key === 'tutorial') sort = -1;
        map.set(key, { key, glyph: '🗺', label: s.zone_label || 'Not zone-specific', items: [], sort });
      }
      map.get(key).items.push(s);
    });
    return [...map.values()].sort((a, b) => a.sort - b.sort);
  }

  if (groupBy.value === 'level') {
    const map = new Map();
    rows.forEach((s) => {
      const b = levelBucket(s.level);
      if (!map.has(b.key)) map.set(b.key, { key: b.key, glyph: '📈', label: b.label, items: [], sort: b.sort });
      map.get(b.key).items.push(s);
    });
    return [...map.values()].sort((a, b) => a.sort - b.sort);
  }

  const order = ['monsters', 'items', 'skills', 'zones', 'dungeons'];
  return order
    .map((type) => ({ key: type, glyph: TYPE_META[type].glyph, label: TYPE_META[type].label, items: rows.filter((s) => s.type === type) }))
    .filter((g) => g.items.length > 0);
});

function tierChipStyle(slot) {
  if (slot.tier_kind === 'rarity') {
    const c = RARITY_COLORS[slot.tier_value] ?? RARITY_COLORS.common;
    return { color: c, borderColor: c, background: c + '1f' };
  }
  if (slot.tier_kind === 'danger') {
    const m = dangerMeta(slot.tier_value);
    return { color: m.color, borderColor: m.color, background: m.bg };
  }
  if (slot.tier_kind === 'difficulty') {
    const m = difficultyMeta(slot.tier_value);
    return { color: m.color, borderColor: m.color, background: m.bg };
  }
  return null;
}

function tierChipLabel(slot) {
  if (slot.tier_kind === 'rarity') return RARITY_LABELS[slot.tier_value] ?? slot.tier_value;
  if (slot.tier_kind === 'skill_tier') return `Tier ${slot.tier_value}`;
  return slot.tier_value;
}

// Live thumbnail for a slot that already has an approved image, so a "done" row shows the actual art
// instead of a glyph placeholder — same treatment as the GM Console's Art Review queue thumbnails.
// slot.sprite (not slot.key) is the real basename since a few monsters alias their art to a shared
// sprite (see GmArtController::approve()'s comment on $baseName).
function slotThumb(slot) {
  if (!slot.sprite) return null;
  if (slot.grades) {
    const approved = slot.grades.find((g) => g.status === 'approved');
    return approved ? `/images/monsters/${slot.sprite}_${approved.grade}.png` : null;
  }
  return slot.single.status === 'approved' ? `/images/${slot.type}/${slot.sprite}.png` : null;
}

// Upload modal — opened either from a Work Board slot/grade button, or from a rejected submission's
// "Resubmit" action (which carries entity_type/entity_id/grade straight off that submission row instead
// of needing to look the slot back up).
const uploadTarget = ref(null);
const uploadFile = ref(null);
const uploadNotes = ref('');
const uploadError = ref('');
const uploading = ref(false);

function openUpload(type, entityId, name, grade, glyph) {
  uploadTarget.value = { type, entityId, name, grade, glyph };
  uploadFile.value = null;
  uploadNotes.value = '';
  uploadError.value = '';
}

function closeUpload() {
  uploadTarget.value = null;
}

function onFileChange(e) {
  uploadFile.value = e.target.files[0] ?? null;
}

async function submitUpload() {
  if (!uploadFile.value) {
    uploadError.value = 'Choose an image file first.';
    return;
  }
  uploading.value = true;
  uploadError.value = '';
  try {
    const form = new FormData();
    form.append('entity_type', uploadTarget.value.type);
    form.append('entity_id', uploadTarget.value.entityId);
    if (uploadTarget.value.grade) form.append('grade', uploadTarget.value.grade);
    if (uploadNotes.value) form.append('notes', uploadNotes.value);
    form.append('file', uploadFile.value);
    await api.post('/gm/art/submit', form);
    closeUpload();
    await Promise.all([loadBoard(), loadMine()]);
  } catch (e) {
    uploadError.value = e.response?.data?.message || 'Could not submit — check the file is a PNG/JPG/WEBP under 4MB.';
  } finally {
    uploading.value = false;
  }
}

function resubmit(sub) {
  openUpload(sub.entity_type, sub.entity_id, sub.entity_label, sub.grade, TYPE_META[sub.entity_type]?.glyph);
}
</script>

<template>
  <div class="art-studio-page">
    <div class="art-studio-banner">
      <div class="art-studio-banner__icon">🎨</div>
      <div>
        <div class="ox art-studio-banner__title">Art Studio</div>
        <div class="art-studio-banner__subtitle">Claim a slot, upload your art, and a GM reviews it before it goes live.</div>
      </div>
    </div>

    <div class="art-studio-stats">
      <div class="art-studio-stat">
        <div class="art-studio-stat__label">Approved</div>
        <div class="ox art-studio-stat__value is-approved">{{ myStats.approved }}</div>
      </div>
      <div class="art-studio-stat">
        <div class="art-studio-stat__label">In review</div>
        <div class="ox art-studio-stat__value is-pending">{{ myStats.pending }}</div>
      </div>
      <div class="art-studio-stat">
        <div class="art-studio-stat__label">Needs changes</div>
        <div class="ox art-studio-stat__value is-rejected">{{ myStats.rejected }}</div>
      </div>
    </div>

    <div class="art-studio-tabs">
      <button type="button" class="art-studio-tab" :class="{ 'is-active': tab === 'board' }" @click="switchTab('board')">Work Board</button>
      <button type="button" class="art-studio-tab" :class="{ 'is-active': tab === 'mine' }" @click="switchTab('mine')">My Submissions</button>
    </div>

    <template v-if="tab === 'board'">
      <div class="art-studio-filter-row">
        <span class="art-studio-filter-row__label">TYPE</span>
        <button type="button" class="art-studio-chip" :class="{ 'is-active': typeFilter === 'all' }" @click="typeFilter = 'all'">All</button>
        <button
          v-for="(meta, type) in TYPE_META"
          :key="type"
          type="button"
          class="art-studio-chip"
          :class="{ 'is-active': typeFilter === type }"
          @click="typeFilter = type"
        >{{ meta.glyph }} {{ meta.label }}</button>
      </div>
      <div class="art-studio-filter-row">
        <span class="art-studio-filter-row__label">GROUP BY</span>
        <button
          v-for="opt in GROUP_BY_OPTIONS"
          :key="opt.key"
          type="button"
          class="art-studio-chip"
          :class="{ 'is-active': groupBy === opt.key }"
          @click="groupBy = opt.key"
        >{{ opt.label }}</button>
        <label class="art-studio-hide-done">
          <input type="checkbox" v-model="hideDone" />
          Only show what needs art
        </label>
      </div>

      <p v-if="!loading && filteredSlots.length === 0" class="art-studio-empty">Nothing matches this filter.</p>

      <div v-for="group in groupedSlots" :key="group.key" class="art-studio-group">
        <div class="art-studio-group__header">
          <span>{{ group.glyph }}</span>
          <span class="ox">{{ group.label }}</span>
          <span class="art-studio-group__count">{{ group.items.length }}</span>
        </div>
        <div class="gm-console-panel-like">
          <div v-for="slot in group.items" :key="`${slot.type}:${slot.entity_id}`" class="art-studio-row" :class="{ 'is-done': slot.done }">
            <div class="art-studio-row__thumb">
              <img v-if="slotThumb(slot)" :src="slotThumb(slot)" :alt="slot.name" />
              <template v-else>{{ slot.glyph }}</template>
            </div>
            <div class="art-studio-row__body">
              <div class="art-studio-row__head">
                <div class="art-studio-row__info">
                  <div class="art-studio-row__name">{{ slot.name }}</div>
                  <div class="art-studio-row__meta">
                    <span v-if="slot.zone_label">{{ slot.zone_label }} · </span>Lv.{{ slot.level ?? '?' }}
                  </div>
                </div>
                <div class="art-studio-row__chips">
                  <span v-if="slot.is_boss" class="art-studio-row__chip">BOSS</span>
                  <span v-if="slot.tier_kind && slot.tier_value" class="art-studio-row__chip" :style="tierChipStyle(slot)">{{ tierChipLabel(slot) }}</span>
                  <span v-if="slot.done" class="art-studio-row__chip is-live">✓ LIVE</span>
                </div>
              </div>

              <div v-if="slot.grades" class="art-studio-row__grades">
                <button
                  v-for="g in slot.grades"
                  :key="g.grade"
                  type="button"
                  class="art-slot-grade"
                  :class="`is-${g.status}`"
                  :style="{ '--grade-color': gradeMeta(g.grade).color }"
                  @click="openUpload(slot.type, slot.entity_id, slot.name, g.grade, slot.glyph)"
                >
                  <span class="art-slot-grade__dot"></span>
                  {{ GRADE_META[g.grade].label }}
                  <span class="art-slot-grade__state">{{ STATUS_LABEL[g.status] }}</span>
                </button>
              </div>
              <button
                v-else
                type="button"
                class="art-slot-card__upload"
                :class="`is-${slot.single.status}`"
                @click="openUpload(slot.type, slot.entity_id, slot.name, null, slot.glyph)"
              >{{ slot.single.status === 'open' ? 'Upload art' : STATUS_LABEL[slot.single.status] }}</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <template v-else>
      <p v-if="submissions.length === 0" class="art-studio-empty">You haven't submitted anything yet — pick a slot on the Work Board.</p>
      <div class="art-studio-group">
        <div class="gm-console-panel-like">
          <div v-for="sub in submissions" :key="sub.id" class="art-studio-row">
            <div class="art-studio-row__body">
              <div class="art-studio-row__head">
                <div class="art-studio-row__info">
                  <div class="art-studio-row__name">{{ sub.entity_label }}</div>
                  <div class="art-studio-row__meta">{{ sub.submitted_at }}</div>
                </div>
                <span class="art-studio-row__chip" :class="`status-${sub.status}`">{{ STATUS_LABEL[sub.status] }}</span>
                <button v-if="sub.status === 'rejected'" type="button" class="art-studio-chip" @click="resubmit(sub)">Resubmit</button>
              </div>
              <div v-if="sub.status === 'rejected' && sub.review_notes" class="art-submission-row__feedback">
                <div class="art-submission-row__feedback-head">GM feedback — {{ sub.reviewed_by }}</div>
                {{ sub.review_notes }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-if="uploadTarget" class="art-upload-overlay" @click.self="closeUpload">
      <div class="art-upload-modal">
        <div class="art-upload-modal__head">
          <div>
            <div class="art-upload-modal__eyebrow">Upload art for</div>
            <div class="ox art-upload-modal__title">{{ uploadTarget.glyph }} {{ uploadTarget.name }}</div>
            <div v-if="uploadTarget.grade" class="art-upload-modal__subtitle">{{ GRADE_META[uploadTarget.grade].label }} grade</div>
          </div>
          <button type="button" class="art-upload-modal__close" @click="closeUpload">✕</button>
        </div>

        <input type="file" accept="image/png,image/jpeg,image/webp" class="art-upload-modal__file" @change="onFileChange" />
        <textarea
          v-model="uploadNotes"
          class="art-upload-modal__notes"
          placeholder="Note for the reviewing GM (optional) — reference used, alternate crops, etc."
        ></textarea>

        <p v-if="uploadError" class="art-upload-modal__error">{{ uploadError }}</p>

        <div class="art-upload-modal__actions">
          <button type="button" class="art-upload-modal__submit" :disabled="uploading" @click="submitUpload">{{ uploading ? 'Submitting…' : 'Submit for review' }}</button>
          <button type="button" class="art-upload-modal__cancel" @click="closeUpload">Cancel</button>
        </div>
        <p class="art-upload-modal__hint">Nothing reaches players until a GM approves it.</p>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./ArtStudioPage.scss" scoped></style>
