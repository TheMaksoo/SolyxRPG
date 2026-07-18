<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';

const characterStore = useCharacterStore();
const friends = ref([]);
const incoming = ref([]);
const browse = ref([]);
const activeFriend = ref(null);
const messages = ref([]);
const draft = ref('');
const message = ref('');

async function load() {
  const { data } = await api.get('/friends');
  friends.value = data.friends;
  incoming.value = data.incoming_requests;
  browse.value = data.browse;
}

async function openThread(character) {
  activeFriend.value = character;
  const { data } = await api.get(`/friends/${character.id}/messages`);
  messages.value = data.messages;
}

async function send() {
  if (!draft.value.trim() || !activeFriend.value) return;
  await api.post(`/friends/${activeFriend.value.id}/messages`, { body: draft.value.trim() });
  draft.value = '';
  await openThread(activeFriend.value);
}

async function sendRequest(character) {
  message.value = '';
  try {
    await api.post(`/friends/${character.id}/request`);
    message.value = `Friend request sent to ${character.name}.`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not send request.';
  }
}

async function accept(friendship) {
  await api.post(`/friends/requests/${friendship.id}/accept`);
  await load();
}

async function decline(friendship) {
  await api.post(`/friends/requests/${friendship.id}/decline`);
  await load();
}

async function toggleFavorite(row) {
  await api.post(`/friends/${row.character.id}/favorite`);
  await load();
}

onMounted(() => {
  load();
  if (!characterStore.character) characterStore.fetch();
});
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">🧑‍🤝‍🧑</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Friends</h1>
    </div>

    <p v-if="message" style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:14px">{{ message }}</p>

    <div v-if="incoming.length" style="margin-bottom:20px;max-width:640px">
      <div style="font-size:11px;letter-spacing:.15em;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:10px">FRIEND REQUESTS</div>
      <div
        v-for="f in incoming"
        :key="f.id"
        style="display:flex;align-items:center;gap:12px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:11px;padding:12px 16px;margin-bottom:8px"
      >
        <span style="flex:1;font-size:13.5px" class="ox">{{ f.requester.name }}</span>
        <button @click="accept(f)" style="padding:7px 14px;border-radius:7px;border:none;background:#e8482f;color:#fff;font-size:12px;font-weight:700;cursor:pointer">Accept</button>
        <button @click="decline(f)" style="padding:7px 14px;border-radius:7px;border:none;background:#1f1f23;color:rgba(255,255,255,.7);font-size:12px;cursor:pointer">Decline</button>
      </div>
    </div>

    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:stretch;min-height:460px">
      <div style="width:300px;flex:none;display:flex;flex-direction:column;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden">
        <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.06)">
          <div class="ox" style="font-weight:700;font-size:14px">Your friends</div>
        </div>
        <div style="flex:1;overflow-y:auto">
          <div
            v-for="row in friends"
            :key="row.character.id"
            @click="openThread(row.character)"
            :style="{
              display: 'flex', alignItems: 'center', gap: '11px', padding: '11px 14px', cursor: 'pointer',
              borderBottom: '1px solid rgba(255,255,255,.04)',
              background: activeFriend?.id === row.character.id ? 'rgba(232,72,47,.1)' : 'transparent',
            }"
          >
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:600">{{ row.character.name }}</div>
              <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:capitalize">{{ row.character.base_class }} · Lv.{{ row.character.level }}</div>
            </div>
            <button @click.stop="toggleFavorite(row)" style="background:none;border:none;color:#eab308;font-size:15px;cursor:pointer">{{ row.favorite ? '★' : '☆' }}</button>
          </div>
          <div v-if="!friends.length" style="padding:16px;font-size:12.5px;color:rgba(255,255,255,.35)">No friends yet — add some below.</div>
        </div>
      </div>

      <div style="flex:1;min-width:300px;display:flex;flex-direction:column;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:12px;overflow:hidden">
        <template v-if="activeFriend">
          <div style="padding:13px 18px;border-bottom:1px solid rgba(255,255,255,.06)">
            <div class="ox" style="font-weight:700;font-size:14px">{{ activeFriend.name }}</div>
          </div>
          <div style="flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px">
            <div v-for="m in messages" :key="m.id" :style="{ display: 'flex', justifyContent: m.sender_id === activeFriend.id ? 'flex-start' : 'flex-end' }">
              <div style="max-width:70%;background:#0e0e10;border-radius:10px;padding:9px 13px;font-size:13px">{{ m.body }}</div>
            </div>
            <div v-if="!messages.length" style="color:rgba(255,255,255,.3);font-size:12.5px">No messages yet — say hi!</div>
          </div>
          <div style="padding:14px 16px;border-top:1px solid rgba(255,255,255,.06);display:flex;gap:10px">
            <input v-model="draft" @keyup.enter="send" placeholder="Message…" style="flex:1;background:#0e0e10;border:1px solid rgba(255,255,255,.1);border-radius:9px;padding:11px 13px;color:#fff;font-size:13px" />
            <button @click="send" style="background:#e8482f;color:#fff;border:none;border-radius:9px;padding:11px 20px;font-weight:700;font-size:13px;cursor:pointer">Send</button>
          </div>
        </template>
        <div v-else style="flex:1;display:flex;flex-direction:column;padding:18px;overflow-y:auto">
          <div class="ox" style="font-weight:700;font-size:13px;margin-bottom:12px">Add friends</div>
          <div
            v-for="c in browse"
            :key="c.id"
            style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05)"
          >
            <span style="flex:1;font-size:13px">{{ c.name }} <span style="color:rgba(255,255,255,.4);text-transform:capitalize">· {{ c.base_class }} Lv.{{ c.level }}</span></span>
            <button @click="sendRequest(c)" style="padding:7px 14px;border-radius:7px;border:1px solid rgba(232,72,47,.3);background:rgba(232,72,47,.1);color:#ff8163;font-size:12px;font-weight:600;cursor:pointer">Add</button>
          </div>
          <div v-if="!browse.length" style="color:rgba(255,255,255,.35);font-size:12.5px">No other players yet.</div>
        </div>
      </div>
    </div>
  </div>
</template>
