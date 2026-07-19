<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const auth = useAuthStore();
const characterStore = useCharacterStore();
const router = useRouter();
const tutorialMessage = ref('');

async function replayTutorial() {
  tutorialMessage.value = '';
  try {
    await api.post('/character/tutorial/restart');
    if (characterStore.character) characterStore.character.tutorial_seen = false;
    router.push('/dashboard');
  } catch (e) {
    tutorialMessage.value = e.response?.data?.message || 'Could not restart the tour.';
  }
}

const providers = ['discord', 'google', 'apple'];

function linkedTo(provider) {
  return auth.user?.social_accounts?.some((a) => a.provider === provider);
}

async function logout() {
  await auth.logout();
  router.push('/landing');
}

const tickets = ref([]);
const ticketForm = ref({ subject: '', body: '' });
const ticketMessage = ref('');

async function loadTickets() {
  const { data } = await api.get('/support-tickets');
  tickets.value = data.tickets;
}

async function submitTicket() {
  ticketMessage.value = '';
  if (!ticketForm.value.subject.trim() || !ticketForm.value.body.trim()) return;
  try {
    await api.post('/support-tickets', ticketForm.value);
    ticketForm.value = { subject: '', body: '' };
    ticketMessage.value = 'Ticket submitted — a GM will get back to you.';
    await loadTickets();
  } catch (e) {
    ticketMessage.value = e.response?.data?.message || 'Could not submit ticket.';
  }
}

onMounted(loadTickets);
</script>

<template>
  <div>
    <div class="settings-header">
      <div class="settings-header__icon">⚙</div>
      <h1 class="ox settings-title">Settings</h1>
    </div>

    <div class="settings-content">
      <div class="linked-accounts-card">
        <h3 class="ox linked-accounts-card__title">Linked Accounts</h3>
        <div v-for="p in providers" :key="p" class="linked-account-row">
          <span class="linked-account-row__label">{{ p }}</span>
          <a
            v-if="!linkedTo(p)"
            :href="`/api/auth/${p}/redirect`"
            class="linked-account-row__link"
          >Link</a>
          <span v-else class="linked-account-row__status">Linked</span>
        </div>
      </div>

      <div class="settings-actions">
        <router-link
          to="/characters"
          class="settings-action-btn"
        >
          Switch Character
        </router-link>
        <button
          @click="replayTutorial"
          class="settings-action-btn"
        >
          Replay Tutorial
        </button>
        <button
          @click="logout"
          class="settings-action-btn"
        >
          Log out
        </button>
      </div>
      <p v-if="tutorialMessage" class="support-card__message">{{ tutorialMessage }}</p>

      <div class="support-card">
        <h3 class="ox support-card__title">Contact Support</h3>
        <p v-if="ticketMessage" class="support-card__message">{{ ticketMessage }}</p>
        <input v-model="ticketForm.subject" placeholder="Subject" class="support-card__input" />
        <textarea v-model="ticketForm.body" rows="3" placeholder="Describe the issue…" class="support-card__textarea"></textarea>
        <button @click="submitTicket" class="settings-action-btn">Submit Ticket</button>

        <div v-if="tickets.length" class="support-card__history">
          <div class="support-card__history-label">YOUR TICKETS</div>
          <div v-for="t in tickets" :key="t.id" class="support-ticket-row">
            <span class="support-ticket-row__subject">{{ t.subject }}</span>
            <span class="support-ticket-row__status">{{ t.status }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./SettingsPage.scss" scoped></style>
