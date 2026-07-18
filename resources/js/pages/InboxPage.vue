<script setup>
import { ref, onMounted } from 'vue';
import api from '../api/client';

const items = ref([]);

async function load() {
  const { data } = await api.get('/inbox');
  items.value = data.items;
}

async function accept(item) {
  await api.post(`/friends/requests/${item.friendship_id}/accept`);
  await load();
}

async function decline(item) {
  await api.post(`/friends/requests/${item.friendship_id}/decline`);
  await load();
}

async function markRead(item) {
  await api.post(`/inbox/${item.id}/read`);
  item.read = true;
}

async function dismiss(item) {
  await api.post(`/inbox/${item.id}/dismiss`);
  items.value = items.value.filter((i) => i !== item);
}

onMounted(load);
</script>

<template>
  <div class="inbox-page">
    <div class="inbox-header">
      <div class="inbox-header__icon">🔔</div>
      <h1 class="ox inbox-title">Inbox</h1>
    </div>

    <p class="inbox-subtitle">Friend requests, purchase receipts, and announcements.</p>

    <div class="inbox-list">
      <div
        v-for="(item, i) in items"
        :key="i"
        class="inbox-item"
        :class="{ 'inbox-item--unread': item.type === 'mail' && !item.read }"
      >
        <div class="inbox-item__icon">{{ item.icon }}</div>
        <div class="inbox-item__content">
          <div class="inbox-item__title-row">
            <div class="ox inbox-item__title">{{ item.title }}</div>
          </div>
          <div class="inbox-item__body">{{ item.body }}</div>
          <div v-if="item.invite" class="inbox-item__actions">
            <button @click="accept(item)" class="inbox-item__accept">Accept</button>
            <button @click="decline(item)" class="inbox-item__decline">Decline</button>
          </div>
          <div v-else-if="item.type === 'mail'" class="inbox-item__actions">
            <button v-if="!item.read" @click="markRead(item)" class="inbox-item__accept">Mark read</button>
            <button @click="dismiss(item)" class="inbox-item__decline">Dismiss</button>
          </div>
        </div>
      </div>
      <div v-if="!items.length" class="inbox-empty">Nothing here yet.</div>
    </div>
  </div>
</template>

<style lang="scss" src="./InboxPage.scss" scoped></style>
