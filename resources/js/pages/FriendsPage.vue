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
    <div class="friends-header">
      <div class="friends-header__icon">🧑‍🤝‍🧑</div>
      <h1 class="ox friends-title">Friends</h1>
    </div>

    <p v-if="message" class="friends-message">{{ message }}</p>

    <div v-if="incoming.length" class="friend-requests">
      <div class="friend-requests__eyebrow">FRIEND REQUESTS</div>
      <div v-for="f in incoming" :key="f.id" class="friend-request">
        <span class="ox friend-request__name">{{ f.requester.name }}</span>
        <button @click="accept(f)" class="friend-request__accept">Accept</button>
        <button @click="decline(f)" class="friend-request__decline">Decline</button>
      </div>
    </div>

    <div class="friends-layout">
      <div class="friend-list-panel">
        <div class="friend-list-panel__header">
          <div class="ox friend-list-panel__header-title">Your friends</div>
        </div>
        <div class="friend-list-panel__body">
          <div
            v-for="row in friends"
            :key="row.character.id"
            @click="openThread(row.character)"
            class="friend-row"
            :class="{ 'is-active': activeFriend?.id === row.character.id }"
          >
            <div class="friend-row__info">
              <router-link
                :to="{ name: 'public-profile', params: { id: row.character.id } }"
                @click.stop
                class="friend-row__name friend-row__name--link"
              >{{ row.character.name }}</router-link>
              <div class="friend-row__meta">{{ row.character.base_class }} · Lv.{{ row.character.level }}</div>
            </div>
            <button @click.stop="toggleFavorite(row)" class="friend-row__favorite">{{ row.favorite ? '★' : '☆' }}</button>
          </div>
          <div v-if="!friends.length" class="friend-list-panel__empty">No friends yet — add some below.</div>
        </div>
      </div>

      <div class="chat-panel">
        <template v-if="activeFriend">
          <div class="chat-panel__header">
            <div class="ox chat-panel__header-title">{{ activeFriend.name }}</div>
          </div>
          <div class="chat-panel__messages">
            <div
              v-for="m in messages"
              :key="m.id"
              class="friend-message"
              :class="{ 'friend-message--incoming': m.sender_id === activeFriend.id }"
            >
              <div class="friend-message__bubble">{{ m.body }}</div>
            </div>
            <div v-if="!messages.length" class="chat-panel__empty">No messages yet — say hi!</div>
          </div>
          <div class="chat-panel__composer">
            <input v-model="draft" @keyup.enter="send" placeholder="Message…" class="chat-panel__input" />
            <button @click="send" class="chat-panel__send">Send</button>
          </div>
        </template>
        <div v-else class="add-friends">
          <div class="ox add-friends__title">Add friends</div>
          <div v-for="c in browse" :key="c.id" class="add-friends__row">
            <span class="add-friends__name"
              >{{ c.name }} <span class="add-friends__meta">· {{ c.base_class }} Lv.{{ c.level }}</span></span
            >
            <button @click="sendRequest(c)" class="add-friends__add">Add</button>
          </div>
          <div v-if="!browse.length" class="add-friends__empty">No other players yet.</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./FriendsPage.scss" scoped></style>
