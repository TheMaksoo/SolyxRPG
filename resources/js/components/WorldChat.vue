<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import api from '../api/client';
import VipBadge from './VipBadge.vue';
import MentionInput from './MentionInput.vue';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import { renderChatBody, mentionsMe } from '../chatMentions';

defineProps({ fullHeight: { type: Boolean, default: false } });

const characterStore = useCharacterStore();
const auth = useAuthStore();
const messages = ref([]);
const body = ref('');
const messagesEl = ref(null);
const lastMessageId = ref(0);
let interval = null;

// World chat has no fixed roster to draw @mention suggestions from — best effort using whoever's
// visible in the last few messages.
const mentionCandidates = computed(() => {
  const seen = new Map();
  for (const m of messages.value) {
    if (m.character) seen.set(m.character.id, m.character.name);
  }
  return [...seen].map(([id, name]) => ({ id, name }));
});

function scrollToBottom() {
  nextTick(() => {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
  });
}

async function load() {
  try {
    const { data } = await api.get('/chat/world');
    messages.value = data.messages.slice(-100);
    if (data.messages.length > 0) {
      lastMessageId.value = data.messages[data.messages.length - 1].id;
    }
    scrollToBottom();
  } catch {
    // silent — chat is a non-critical side panel
  }
}

async function checkForNewMessages() {
  try {
    const { data } = await api.get('/status/check');
    // Only fetch full messages if there are new ones
    if (data.last_message_id > lastMessageId.value) {
      await load();
    }
  } catch {
    // silent
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
  <div class="world-chat" :class="{ 'world-chat--full-height': fullHeight }">
    <div class="world-chat__header">World Chat</div>
    <div class="world-chat__messages" ref="messagesEl">
      <div
        v-for="m in messages"
        :key="m.id"
        class="world-chat__line"
        :class="{ 'is-mention-me': auth.user?.preferences?.highlight_mentions !== false && mentionsMe(m.body, characterStore.character?.name) }"
      >
        <strong :style="{ color: m.character?.active_color?.value }">{{ m.character?.name }}:</strong>
        <VipBadge :tier="m.vip_tier" />
        <span v-html="renderChatBody(m.body, characterStore.character?.name, mentionCandidates.map((c) => c.name))"></span>
      </div>
      <div v-if="!messages.length" class="world-chat__empty">No messages yet. Say hi!</div>
    </div>
    <div class="world-chat__input-row">
      <MentionInput
        v-model="body"
        :candidates="mentionCandidates"
        maxlength="300"
        placeholder="Say something…"
        class="world-chat__input"
        @enter="send"
      />
      <button @click="send" class="world-chat__send">Send</button>
    </div>
  </div>
</template>

<style lang="scss" src="./WorldChat.scss" scoped></style>
