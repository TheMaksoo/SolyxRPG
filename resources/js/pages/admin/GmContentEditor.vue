<script setup>
import { ref, watch } from 'vue';
import api from '../../api/client';
import { RESOURCE_SCHEMAS } from './resourceSchemas';

const props = defineProps({ resource: { type: String, required: true } });

const rows = ref([]);
const editing = ref(null); // row being edited, or {} for new
const form = ref({});
const message = ref('');

const schema = () => RESOURCE_SCHEMAS[props.resource];

function blankForm() {
  const f = {};
  for (const field of schema().fields) {
    f[field.name] = field.type === 'checkbox' ? false : field.type === 'json' ? '{}' : '';
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

    <div class="twrap gm-editor-table-wrap">
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
