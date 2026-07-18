<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const guild = ref(null);
const browse = ref([]);
const message = ref('');
const chatBody = ref('');
const createForm = ref({ name: '', tag: '' });

async function load() {
  const { data } = await api.get('/guild');
  guild.value = data.guild;
  browse.value = data.browse || [];
}

async function create() {
  message.value = '';
  try {
    await api.post('/guild', createForm.value);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not create guild.';
  }
}

async function join(g) {
  message.value = '';
  try {
    await api.post(`/guild/${g.id}/join`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not join.';
  }
}

async function send() {
  if (!chatBody.value.trim()) return;
  await api.post(`/guild/${guild.value.id}/message`, { body: chatBody.value.trim() });
  chatBody.value = '';
  await load();
}

onMounted(load);
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🛡</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Guild</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:#ff6a4d;margin-bottom:14px">{{ message }}</p>

    <div v-if="guild" style="max-width:600px">
      <h2 class="ox" style="font-size:18px;margin:0 0 4px">{{ guild.name }} [{{ guild.tag }}]</h2>
      <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:16px">{{ guild.members?.length || 0 }} / {{ guild.member_cap }} members</div>

      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px">
        <span
          v-for="m in guild.members"
          :key="m.id"
          style="font-size:12px;background:#151517;border:1px solid rgba(255,255,255,.08);padding:6px 12px;border-radius:20px"
        >
          {{ m.character?.name }} <span style="color:rgba(255,255,255,.35)">· {{ m.role }}</span>
        </span>
      </div>

      <div style="background:#0e0e10;border-radius:10px;padding:14px;max-height:220px;overflow-y:auto;margin-bottom:12px">
        <div v-for="m in [...(guild.messages || [])].reverse()" :key="m.id" style="font-size:12.5px;margin-bottom:6px">
          <strong>{{ m.character?.name }}:</strong> {{ m.body }}
        </div>
        <div v-if="!guild.messages?.length" style="color:rgba(255,255,255,.3);font-size:12.5px">No messages yet.</div>
      </div>
      <div style="display:flex;gap:8px">
        <input
          v-model="chatBody"
          @keyup.enter="send"
          placeholder="Message the guild…"
          style="flex:1;padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:13.5px"
        />
        <button @click="send" style="padding:10px 18px;border-radius:10px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer">Send</button>
      </div>
    </div>

    <div v-else style="max-width:600px">
      <div style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:18px;margin-bottom:20px">
        <h3 class="ox" style="margin:0 0 12px;font-size:14px">Start a guild</h3>
        <div style="display:flex;gap:8px">
          <input v-model="createForm.name" placeholder="Guild name" style="flex:1;padding:9px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:13px" />
          <input v-model="createForm.tag" maxlength="5" placeholder="TAG" style="width:80px;padding:9px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:#0e0e10;color:#fff;font-size:13px" />
          <button @click="create" style="padding:9px 16px;border-radius:8px;border:none;background:#e8482f;color:#fff;font-weight:700;cursor:pointer">Create</button>
        </div>
      </div>

      <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Browse guilds</h3>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div
          v-for="g in browse"
          :key="g.id"
          style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:11px;padding:12px 16px;display:flex;align-items:center;gap:12px"
        >
          <span style="flex:1;font-size:13.5px" class="ox">{{ g.name }} [{{ g.tag }}]</span>
          <span style="font-size:12px;color:rgba(255,255,255,.4)">{{ g.members_count }} / {{ g.member_cap }}</span>
          <button @click="join(g)" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-size:12px;cursor:pointer">Join</button>
        </div>
      </div>
    </div>
  </div>
</template>
