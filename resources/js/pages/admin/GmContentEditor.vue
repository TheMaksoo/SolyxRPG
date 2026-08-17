<script setup>
import { ref, computed, watch } from 'vue';
import api from '../../api/client';
import { RESOURCE_SCHEMAS, SEEDER_BACKED_RESOURCES, LEVEL_FIELD, ZONE_SCOPED_RESOURCES, badgeTone } from './resourceSchemas';
import { tagMeta, visibilityMeta } from '../../utils/changelogMeta';
import Toast from '../../components/Toast.vue';

const props = defineProps({ resource: { type: String, required: true } });

const rows = ref([]);
const editing = ref(null); // null | 'new' | row id
const form = ref({});
const message = ref('');
const messageTone = ref('info'); // 'info' | 'error'
let messageToastTimer = null;
watch(message, (val) => {
  clearTimeout(messageToastTimer);
  if (val) {
    const delay = messageTone.value === 'error' ? 5000 : 4000;
    messageToastTimer = setTimeout(() => { message.value = ''; }, delay);
  }
});

const schema = () => RESOURCE_SCHEMAS[props.resource];
const seederEligible = computed(() => SEEDER_BACKED_RESOURCES.includes(props.resource));

// Real art if this row has any (see GmArtController::approve() for the naming convention — monsters are
// always shown at their Common grade here since this grid has no per-grade concept), falling back to the
// glyph exactly like everywhere else in the game that renders a sprite. brokenImages tracks any URL that
// 404s (e.g. the file was removed by hand) so it falls back instead of showing a broken-image icon forever.
const brokenImages = ref({});
function cardImage(row) {
  if (!row.sprite) return null;
  const src = props.resource === 'monsters' ? `/images/monsters/${row.sprite}_common.png` : `/images/${props.resource}/${row.sprite}.png`;
  return brokenImages.value[src] ? null : src;
}
function onImageError(src) {
  brokenImages.value[src] = true;
}

// Changelogs get a dedicated card layout (see template below) instead of the generic grid — body text
// and visibility need more room than a stat-chip card gives them. Newest first, since that's what a GM
// editing/checking recent entries actually wants (the API itself orders by id for every other resource,
// which is fine for those but reads backwards for a changelog).
const isChangelogs = computed(() => props.resource === 'changelogs');
const changelogRows = computed(() => [...rows.value].sort((a, b) => new Date(b.published_at) - new Date(a.published_at)));

// "Group by" — same low-to-high level-bucket / zone grouping the Art Studio work board uses, applied
// here to the raw content list. Only offered when the resource actually has the relevant column: most
// resources have a level (min_level or, for skills, level_req), but only monsters have a real zone_id.
const groupBy = ref('none'); // 'none' | 'level' | 'zone'
const zoneNames = ref({}); // zone id -> name, fetched once and cached across resource switches

const levelField = computed(() => LEVEL_FIELD[props.resource] ?? null);
const canGroupByZone = computed(() => ZONE_SCOPED_RESOURCES.includes(props.resource));

async function ensureZoneNames() {
  if (Object.keys(zoneNames.value).length) return;
  const { data } = await api.get('/gm/zones');
  zoneNames.value = Object.fromEntries(data.zones.map((z) => [z.id, z.name]));
}

watch(groupBy, (v) => {
  if (v === 'zone' && canGroupByZone.value) ensureZoneNames();
});

function levelBucket(level) {
  if (level === null || level === undefined || level === '') return { label: 'No level set', sort: Infinity };
  const start = Math.floor((Number(level) - 1) / 25) * 25 + 1;
  return { label: `Lv ${start}–${start + 24}`, sort: start };
}

// Falls back to the flat (ungrouped) list as a single unlabeled section, so the template only ever
// needs to render one shape (a list of sections) regardless of whether grouping is on.
const sections = computed(() => {
  if (groupBy.value === 'level' && levelField.value) {
    const map = new Map();
    for (const row of rows.value) {
      const b = levelBucket(row[levelField.value]);
      if (!map.has(b.label)) map.set(b.label, { label: b.label, sort: b.sort, rows: [] });
      map.get(b.label).rows.push(row);
    }
    return [...map.values()].sort((a, b) => a.sort - b.sort);
  }

  if (groupBy.value === 'zone' && canGroupByZone.value) {
    const map = new Map();
    for (const row of rows.value) {
      const label = row.zone_id ? (zoneNames.value[row.zone_id] ?? `Zone #${row.zone_id}`) : 'No zone (tutorial)';
      const sort = row.zone_id ?? Infinity;
      if (!map.has(label)) map.set(label, { label, sort, rows: [] });
      map.get(label).rows.push(row);
    }
    return [...map.values()].sort((a, b) => a.sort - b.sort);
  }

  return [{ label: null, sort: 0, rows: rows.value }];
});

function previewFields() {
  const s = schema();
  if (s.previewFields) return s.previewFields;
  return s.columns.filter((c) => !['id', 'name', 'key'].includes(c)).slice(0, 4);
}

function fieldLabel(name) {
  return name.replace(/_json$/, '').replace(/_/g, ' ');
}

function formatPreviewValue(row, name) {
  const value = row[name];
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (value === null || value === undefined || value === '') return '—';
  return value;
}

function isBadgeField(name) {
  return (schema().badgeFields || []).includes(name);
}

function blankForm() {
  const f = {};
  for (const field of schema().fields) {
    if (field.type === 'checkbox') f[field.name] = false;
    else if (field.type === 'json') f[field.name] = '{}';
    else if (field.type === 'select') f[field.name] = field.options?.[0] ?? '';
    else f[field.name] = '';
  }
  return f;
}

async function load() {
  const { data } = await api.get(`/gm/${props.resource}`);
  rows.value = data[props.resource];
}

function startCreate() {
  editing.value = 'new';
  message.value = '';
  form.value = blankForm();
}

function startEdit(row) {
  editing.value = row.id;
  message.value = '';
  const f = {};
  for (const field of schema().fields) {
    f[field.name] = field.type === 'json' ? JSON.stringify(row[field.name] ?? {}, null, 2) : row[field.name];
  }
  form.value = f;
}

function cancelEdit() {
  editing.value = null;
}

async function save() {
  message.value = '';
  const payload = {};
  for (const field of schema().fields) {
    if (field.type === 'json') {
      try {
        payload[field.name] = JSON.parse(form.value[field.name] || '{}');
      } catch {
        message.value = `Invalid JSON in ${field.name}.`;
        messageTone.value = 'error';
        return;
      }
    } else if (field.type === 'number') {
      const raw = form.value[field.name];
      payload[field.name] = raw === '' || raw === null || raw === undefined ? null : Number(raw);
    } else {
      payload[field.name] = form.value[field.name];
    }
  }

  try {
    let data;
    if (editing.value !== 'new') {
      ({ data } = await api.put(`/gm/${props.resource}/${editing.value}`, payload));
    } else {
      ({ data } = await api.post(`/gm/${props.resource}`, payload));
    }
    editing.value = null;
    await load();

    if (!seederEligible.value) {
      message.value = 'Saved.';
    } else if (data.seeder_synced) {
      message.value = 'Saved — seeder file updated.';
    } else {
      message.value = "Saved to DB, but the seeder file couldn't be updated automatically (check server log).";
    }
    messageTone.value = data.seeder_synced === false && seederEligible.value ? 'error' : 'info';
  } catch (e) {
    message.value = e.response?.data?.message || 'Save failed.';
    messageTone.value = 'error';
  }
}

async function remove(row) {
  if (!confirm(`Delete "${row.name || row.key}"?`)) return;
  await api.delete(`/gm/${props.resource}/${row.id}`);
  if (editing.value === row.id) editing.value = null;
  await load();
}

function downloadSeeder() {
  window.open(`/api/gm/${props.resource}/seeder/download`, '_blank');
}

watch(() => props.resource, () => { editing.value = null; message.value = ''; groupBy.value = 'none'; load(); }, { immediate: true });
</script>

<template>
  <div>
    <div class="gm-editor-header">
      <div class="ox gm-editor-header__title">{{ schema().label }} ({{ rows.length }})</div>
      <div class="gm-editor-header__actions">
        <button
          @click="downloadSeeder"
          :disabled="!seederEligible"
          :title="seederEligible ? 'Download the current seeder file for this resource' : `This resource isn't seeder-backed — edits are DB-only.`"
          class="gm-editor-download-btn"
        >
          ⬇ Download Seeder
        </button>
        <button @click="startCreate" class="gm-editor-new-btn">+ New</button>
      </div>
    </div>

    <Toast :message="message" :type="messageTone === 'error' ? 'error' : 'success'" />

    <div v-if="!isChangelogs && (levelField || canGroupByZone)" class="gm-editor-group-row">
      <span class="gm-editor-group-row__label">GROUP BY</span>
      <button type="button" class="gm-editor-group-btn" :class="{ 'is-active': groupBy === 'none' }" @click="groupBy = 'none'">None</button>
      <button v-if="levelField" type="button" class="gm-editor-group-btn" :class="{ 'is-active': groupBy === 'level' }" @click="groupBy = 'level'">Level</button>
      <button v-if="canGroupByZone" type="button" class="gm-editor-group-btn" :class="{ 'is-active': groupBy === 'zone' }" @click="groupBy = 'zone'">Zone</button>
    </div>

    <div v-if="isChangelogs" class="gm-changelog-cards">
      <div v-for="row in changelogRows" :key="row.id" class="gm-changelog-card">
        <div class="gm-changelog-card__head">
          <span class="gm-changelog-card__version">v{{ row.version }}</span>
          <span class="gm-changelog-card__tag" :class="`gm-changelog-card__tag--${row.tag}`">
            {{ tagMeta(row.tag).icon }} {{ tagMeta(row.tag).label }}
          </span>
          <span
            class="gm-changelog-card__visibility"
            :class="`gm-changelog-card__visibility--${row.visibility}`"
            :title="visibilityMeta(row.visibility).hint"
          >
            {{ visibilityMeta(row.visibility).icon }} {{ visibilityMeta(row.visibility).label }}
          </span>
          <span class="ox gm-changelog-card__title">{{ row.title }}</span>
          <span class="gm-changelog-card__date">{{ row.published_at }}</span>
        </div>
        <p class="gm-changelog-card__body">{{ row.body }}</p>
        <div class="gm-changelog-card__actions">
          <button @click="startEdit(row)" class="gm-editor-edit-link">Edit</button>
          <button @click="remove(row)" class="gm-editor-delete-link">Delete</button>
        </div>

        <transition name="gm-expand">
          <div v-if="editing === row.id" class="gm-editor-form">
            <div class="gm-editor-form__fields">
              <div
                v-for="field in schema().fields"
                :key="field.name"
                :class="{ 'gm-editor-field--full': field.type === 'json' || field.type === 'textarea' }"
              >
                <div class="gm-editor-field__label">{{ fieldLabel(field.name) }}</div>
                <select v-if="field.type === 'select'" v-model="form[field.name]" class="gm-editor-select">
                  <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
                </select>
                <textarea
                  v-else-if="field.type === 'json' || field.type === 'textarea'"
                  v-model="form[field.name]"
                  rows="3"
                  class="gm-editor-textarea"
                ></textarea>
                <input v-else-if="field.type === 'checkbox'" type="checkbox" v-model="form[field.name]" class="gm-editor-checkbox" />
                <input v-else :type="field.type === 'number' ? 'number' : 'text'" v-model="form[field.name]" class="gm-editor-input" />
              </div>
            </div>
            <div class="gm-editor-form__actions">
              <button @click="save" class="gm-editor-save-btn">Save</button>
              <button @click="cancelEdit" class="gm-editor-cancel-btn">Cancel</button>
            </div>
          </div>
        </transition>
      </div>
      <div v-if="!changelogRows.length" class="gm-editor-empty">No entries yet.</div>
    </div>

    <div v-else>
      <transition name="gm-expand">
        <div v-if="editing === 'new'" class="gm-editor-grid">
          <div class="gm-editor-card gm-editor-card--new">
            <div class="gm-editor-card__head">
              <div class="ox gm-editor-card__title">New {{ schema().label.replace(/s$/, '') }}</div>
            </div>
            <div class="gm-editor-form">
              <div class="gm-editor-form__fields">
                <div
                  v-for="field in schema().fields"
                  :key="field.name"
                  :class="{ 'gm-editor-field--full': field.type === 'json' || field.type === 'textarea' }"
                >
                  <div class="gm-editor-field__label">{{ fieldLabel(field.name) }}</div>
                  <select v-if="field.type === 'select'" v-model="form[field.name]" class="gm-editor-select">
                    <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
                  </select>
                  <textarea
                    v-else-if="field.type === 'json' || field.type === 'textarea'"
                    v-model="form[field.name]"
                    rows="3"
                    class="gm-editor-textarea"
                  ></textarea>
                  <input v-else-if="field.type === 'checkbox'" type="checkbox" v-model="form[field.name]" class="gm-editor-checkbox" />
                  <input v-else :type="field.type === 'number' ? 'number' : 'text'" v-model="form[field.name]" class="gm-editor-input" />
                </div>
              </div>
              <div class="gm-editor-form__actions">
                <button @click="save" class="gm-editor-save-btn">Create</button>
                <button @click="cancelEdit" class="gm-editor-cancel-btn">Cancel</button>
              </div>
            </div>
          </div>
        </div>
      </transition>

      <div v-for="section in sections" :key="section.label ?? '__all'">
        <div v-if="section.label" class="gm-editor-section-header">
          <span class="ox">{{ section.label }}</span>
          <span class="gm-editor-section-header__count">{{ section.rows.length }}</span>
        </div>
        <div class="gm-editor-grid">
      <div v-for="row in section.rows" :key="row.id" class="gm-editor-card" :class="{ 'gm-editor-card--open': editing === row.id }">
        <div class="gm-editor-card__head">
          <div class="gm-editor-card__glyph">
            <img v-if="cardImage(row)" :src="cardImage(row)" :alt="row.name" @error="onImageError(cardImage(row))" />
            <template v-else>{{ row.glyph || schema().label[0] }}</template>
          </div>
          <div class="gm-editor-card__heading">
            <div class="ox gm-editor-card__title">{{ row.name || row.key || row.title }}</div>
            <div v-if="row.key" class="gm-editor-card__key">{{ row.key }}</div>
          </div>
          <div v-if="row.enabled === false || row.locked" class="gm-editor-card__disabled-flag">Disabled</div>
        </div>

        <div class="gm-editor-card__chips">
          <span
            v-for="f in previewFields()"
            :key="f"
            class="gm-editor-chip"
            :class="isBadgeField(f) ? `gm-editor-chip--badge gm-editor-chip--${badgeTone(f, row[f])}` : ''"
          >
            <span class="gm-editor-chip__label">{{ fieldLabel(f) }}</span>
            <span class="gm-editor-chip__value">{{ formatPreviewValue(row, f) }}</span>
          </span>
        </div>

        <div class="gm-editor-card__actions">
          <button @click="startEdit(row)" class="gm-editor-edit-link">Edit</button>
          <button @click="remove(row)" class="gm-editor-delete-link">Delete</button>
        </div>

        <transition name="gm-expand">
          <div v-if="editing === row.id" class="gm-editor-form">
            <div class="gm-editor-form__fields">
              <div
                v-for="field in schema().fields"
                :key="field.name"
                :class="{ 'gm-editor-field--full': field.type === 'json' || field.type === 'textarea' }"
              >
                <div class="gm-editor-field__label">{{ fieldLabel(field.name) }}</div>
                <select v-if="field.type === 'select'" v-model="form[field.name]" class="gm-editor-select">
                  <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
                </select>
                <textarea
                  v-else-if="field.type === 'json' || field.type === 'textarea'"
                  v-model="form[field.name]"
                  rows="3"
                  class="gm-editor-textarea"
                ></textarea>
                <input v-else-if="field.type === 'checkbox'" type="checkbox" v-model="form[field.name]" class="gm-editor-checkbox" />
                <input v-else :type="field.type === 'number' ? 'number' : 'text'" v-model="form[field.name]" class="gm-editor-input" />
              </div>
            </div>
            <div class="gm-editor-form__actions">
              <button @click="save" class="gm-editor-save-btn">Save</button>
              <button @click="cancelEdit" class="gm-editor-cancel-btn">Cancel</button>
            </div>
          </div>
        </transition>
      </div>
        </div>
      </div>

      <div v-if="!rows.length" class="gm-editor-empty">No entries yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./GmContentEditor.scss" scoped></style>
