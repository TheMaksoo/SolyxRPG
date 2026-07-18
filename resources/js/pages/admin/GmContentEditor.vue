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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="ox" style="font-weight:700;font-size:15px">{{ schema().label }} ({{ rows.length }})</div>
      <button
        @click="startCreate"
        style="padding:8px 16px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer"
      >
        + New
      </button>
    </div>

    <div v-if="editing" style="background:#151517;border:1px solid rgba(232,72,47,.3);border-radius:12px;padding:18px;margin-bottom:16px">
      <div class="ox" style="font-weight:700;font-size:13px;margin-bottom:12px">{{ editing.id ? 'Edit' : 'Create' }} {{ schema().label.replace(/s$/, '') }}</div>
      <p v-if="message" style="font-size:12.5px;color:#ff6a4d;margin-bottom:10px">{{ message }}</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px">
        <div v-for="field in schema().fields" :key="field.name" :style="field.type === 'json' || field.type === 'textarea' ? 'grid-column:1/-1' : ''">
          <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:4px;text-transform:capitalize">{{ field.name.replace(/_/g, ' ') }}</div>
          <select
            v-if="field.type === 'select'"
            v-model="form[field.name]"
            style="width:100%;background:#0e0e10;border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:8px;color:#fff;font-size:12.5px;box-sizing:border-box"
          >
            <option v-for="o in field.options" :key="o" :value="o">{{ o }}</option>
          </select>
          <textarea
            v-else-if="field.type === 'json' || field.type === 'textarea'"
            v-model="form[field.name]"
            rows="3"
            style="width:100%;background:#0e0e10;border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:8px;color:#fff;font-size:12px;font-family:ui-monospace,monospace;box-sizing:border-box"
          ></textarea>
          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            v-model="form[field.name]"
            style="width:18px;height:18px"
          />
          <input
            v-else
            :type="field.type === 'number' ? 'number' : 'text'"
            v-model="form[field.name]"
            style="width:100%;background:#0e0e10;border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:8px;color:#fff;font-size:12.5px;box-sizing:border-box"
          />
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button @click="save" style="padding:8px 18px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer">Save</button>
        <button @click="cancelEdit" style="padding:8px 18px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-size:12.5px;cursor:pointer">Cancel</button>
      </div>
    </div>

    <div class="twrap" style="overflow-x:auto;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px">
        <thead>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <th v-for="col in schema().columns" :key="col" style="text-align:left;padding:10px 14px;color:rgba(255,255,255,.4);font-weight:600;text-transform:uppercase;font-size:10.5px">{{ col }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" style="border-bottom:1px solid rgba(255,255,255,.04)">
            <td v-for="col in schema().columns" :key="col" style="padding:9px 14px">
              <span v-if="typeof row[col] === 'boolean'">{{ row[col] ? '✔' : '—' }}</span>
              <span v-else>{{ row[col] }}</span>
            </td>
            <td style="padding:9px 14px;text-align:right;white-space:nowrap">
              <button @click="startEdit(row)" style="background:none;border:none;color:#ff8163;font-size:12px;cursor:pointer;margin-right:10px">Edit</button>
              <button @click="remove(row)" style="background:none;border:none;color:rgba(255,255,255,.4);font-size:12px;cursor:pointer">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!rows.length" style="padding:20px;color:rgba(255,255,255,.35);font-size:12.5px">No entries yet.</div>
    </div>
  </div>
</template>
