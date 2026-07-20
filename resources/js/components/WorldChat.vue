<script setup>
import { ref, nextTick, onMounted, onUnmounted } from 'vue';
import api from '../api/client';
import VipBadge from './VipBadge.vue';

const messages = ref([]);
const body = ref('');
const messagesEl = ref(null);
let interval = null;

function scrollToBottom() {
  nextTick(() => {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
  });
}

async function load() {
  try {
    const { data } = await api.get('/chat/world');
    // Server already caps this at the last 10 messages — keep it capped client-side too in case
    // that ever changes, and always pin the view to the newest message.
    messages.value = data.messages.slice(-10);
    scrollToBottom();
  } catch {
    // silent — chat is a non-critical side panel
  }
}

async function send() {
  const trimmed = body.value.trim();
  if (!trimmed) return;
  body.value = '';
  await api.post('/chat/world', { body: trimmed });
  await load();
}

onMounted(() => {
  load();
  interval = setInterval(load, 5000);
});

onUnmounted(() => {
  if (interval) clearInterval(interval);
});
</script>

<template>
  <div class="world-chat">
    <div class="world-chat__header">World Chat</div>
    <div class="world-chat__messages" ref="messagesEl">
      <div v-for="m in messages" :key="m.id" class="world-chat__line">
        <strong>{{ m.character?.name }}:</strong>
        <VipBadge :tier="m.vip_tier" />
        {{ m.body }}
      </div>
      <div v-if="!messages.length" class="world-chat__empty">No messages yet. Say hi!</div>
    </div>
    <div class="world-chat__input-row">
      <input v-model="body" @keyup.enter="send" maxlength="300" placeholder="Say something…" class="world-chat__input" />
      <button @click="send" class="world-chat__send">Send</button>
    </div>
  </div>
</template>

<style lang="scss" src="./WorldChat.scss" scoped></style>
