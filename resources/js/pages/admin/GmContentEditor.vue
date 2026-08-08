<script setup>
import { ref, computed, watch } from 'vue';
import api from '../../api/client';
import { RESOURCE_SCHEMAS } from './resourceSchemas';
import { tagMeta, visibilityMeta } from '../../utils/changelogMeta';

const props = defineProps({ resource: { type: String, required: true } });

const rows = ref([]);
const editing = ref(null); // row being edited, or {} for new
const form = ref({});
const message = ref('');

const schema = () => RESOURCE_SCHEMAS[props.resource];

// Changelogs get a dedicated card layout (see template below) instead of the generic table —
// body text and visibility need more room than a table cell gives them. Newest first, since
// that's what a GM editing/checking recent entries actually wants (the API itself orders by id
// for every other resource, which is fine for those but reads backwards for a changelog).
const isChangelogs = computed(() => props.resource === 'changelogs');
const changelogRows = computed(() => [...rows.value].sort((a, b) => new Date(b.published_at) - new Date(a.published_at)));

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
  editing.value = {};
  form.value = blankForm();
}

function startEdit(row) {
  editing.value = row;
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
    if (editing.value?.id) {
      await api.put(`/gm/${props.resource}/${editing.value.id}`, payload);
    } else {
      await api.post(`/gm/${props.resource}`, payload);
    }
    editing.value = null;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Save failed.';
  }
}

async function remove(row) {
  if (!confirm(`Delete "${row.name || row.key}"?`)) return;
  await api.delete(`/gm/${props.resource}/${row.id}`);
  await load();
}

watch(() => props.resource, load, { immediate: true });
</script>

<template>
  <div>
    <div class="gm-editor-header">
      <div class="ox gm-editor-header__title">{{ schema().label }} ({{ rows.length }})</div>
      <button @click="startCreate" class="gm-editor-new-btn">
        + New
      </button>
    </div>

    <div v-if="editing" class="gm-editor-form">
      <div class="ox gm-editor-form__title">{{ editing.id ? 'Edit' : 'Create' }} {{ schema().label.replace(/s$/, '') }}</div>
      <p v-if="message" class="gm-editor-form__error">{{ message }}</p>
      <div class="gm-editor-form__fields">
        <div
          v-for="field in schema().fields"
          :key="field.name"
          :class="{ 'gm-editor-field--full': field.type === 'json' || field.type === 'textarea' }"
        >
          <div class="gm-editor-field__label">{{ field.name.replace(/_/g, ' ') }}</div>
          <select
            v-if="field.type === 'select'"
            v-model="form[field.name]"
            class="gm-editor-select"
          >
            <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
          </select>
          <textarea
            v-else-if="field.type === 'json' || field.type === 'textarea'"
            v-model="form[field.name]"
            rows="3"
            class="gm-editor-textarea"
          ></textarea>
          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            v-model="form[field.name]"
            class="gm-editor-checkbox"
          />
          <input
            v-else
            :type="field.type === 'number' ? 'number' : 'text'"
            v-model="form[field.name]"
            class="gm-editor-input"
          />
        </div>
      </div>
      <div class="gm-editor-form__actions">
        <button @click="save" class="gm-editor-save-btn">Save</button>
        <button @click="cancelEdit" class="gm-editor-cancel-btn">Cancel</button>
      </div>
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
      </div>
      <div v-if="!changelogRows.length" class="gm-editor-empty">No entries yet.</div>
    </div>

    <div v-else class="twrap gm-editor-table-wrap">
      <table class="gm-editor-table">
        <thead>
          <tr class="gm-editor-table__head-row">
            <th v-for="col in schema().columns" :key="col" class="gm-editor-table__head-cell">{{ col }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="gm-editor-table__row">
            <td v-for="col in schema().columns" :key="col" class="gm-editor-table__cell">
              <span v-if="typeof row[col] === 'boolean'">{{ row[col] ? '✔' : '—' }}</span>
              <span v-else>{{ row[col] }}</span>
            </td>
            <td class="gm-editor-table__cell--actions">
              <button @click="startEdit(row)" class="gm-editor-edit-link">Edit</button>
              <button @click="remove(row)" class="gm-editor-delete-link">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!rows.length" class="gm-editor-empty">No entries yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./GmContentEditor.scss" scoped></style>
