<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const auth = useAuthStore();
const characterStore = useCharacterStore();
const router = useRouter();
const route = useRoute();
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

const providers = ['discord', 'google'];

function linkedTo(provider) {
  return auth.user?.social_accounts?.some((a) => a.provider === provider);
}

const linkMessage = ref('');

async function handleLinkRedirect() {
  const { linked, link_error: linkError, oauth_error: oauthError } = route.query;
  if (!linked && !linkError && !oauthError) return;

  if (linked) {
    await auth.fetchMe();
    linkMessage.value = `${linked} linked to your account.`;
  } else if (linkError === 'already_linked_elsewhere') {
    linkMessage.value = 'That account is already linked to a different Solyx login.';
  } else if (oauthError === 'cancelled') {
    linkMessage.value = 'Linking was cancelled.';
  } else if (oauthError === 'failed') {
    linkMessage.value = 'Could not link that account. Please try again.';
  }

  router.replace({ query: {} });
}

async function logout() {
  await auth.logout();
  router.push('/landing');
}

// Self-serve tester toggle — lets an already-designated tester (granted via GM/DB) flip their own
// perks on/off to preview the game as a regular player, without needing a GM to do it each time.
const canToggleTester = computed(() => !!auth.user && (auth.user.is_tester || auth.user.role === 'tester'));
const testerMessage = ref('');

async function toggleTesterMode() {
  testerMessage.value = '';
  try {
    const { data } = await api.post('/me/tester-mode');
    auth.user.tester_mode_disabled = data.tester_mode_disabled;
  } catch (e) {
    testerMessage.value = e.response?.data?.message || 'Could not toggle tester mode.';
  }
}

const preferencesMessage = ref('');

// Defaults mirror the backend's default-on/off behavior when a key hasn't been set yet — highlighting
// is opt-out, compact log is opt-in.
const highlightMentions = computed(() => auth.user?.preferences?.highlight_mentions !== false);
const compactBattleLog = computed(() => !!auth.user?.preferences?.compact_battle_log);

async function togglePreference(key, currentValue) {
  preferencesMessage.value = '';
  try {
    const { data } = await api.put('/me/preferences', { [key]: !currentValue });
    auth.user.preferences = data.preferences;
  } catch (e) {
    preferencesMessage.value = e.response?.data?.message || 'Could not save preference.';
  }
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

const expandedTicket = ref(null);
const replyBody = ref('');
const replyMessage = ref('');

function toggleTicket(ticket) {
  expandedTicket.value = expandedTicket.value === ticket.id ? null : ticket.id;
  replyBody.value = '';
  replyMessage.value = '';
}

async function sendReply(ticket) {
  replyMessage.value = '';
  if (!replyBody.value.trim()) return;
  try {
    await api.post(`/support-tickets/${ticket.id}/messages`, { body: replyBody.value });
    replyBody.value = '';
    await loadTickets();
  } catch (e) {
    replyMessage.value = e.response?.data?.message || 'Could not send reply.';
  }
}

onMounted(() => {
  loadTickets();
  handleLinkRedirect();
});
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
        <p v-if="linkMessage" class="support-card__message">{{ linkMessage }}</p>
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

      <div class="preferences-card">
        <h3 class="ox preferences-card__title">Preferences</h3>
        <div class="preferences-row">
          <span class="preferences-row__label">Highlight mentions in chat</span>
          <label class="toggle-switch">
            <input
              type="checkbox"
              aria-label="Highlight mentions in chat"
              :checked="highlightMentions"
              @change="togglePreference('highlight_mentions', highlightMentions)"
            />
            <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
          </label>
        </div>
        <div class="preferences-row">
          <span class="preferences-row__label">Condensed battle log</span>
          <label class="toggle-switch">
            <input
              type="checkbox"
              aria-label="Condensed battle log"
              :checked="compactBattleLog"
              @change="togglePreference('compact_battle_log', compactBattleLog)"
            />
            <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
          </label>
        </div>
        <p v-if="preferencesMessage" class="support-card__message">{{ preferencesMessage }}</p>
      </div>

      <div v-if="canToggleTester" class="customize-tester-toggle">
        <p class="customize-tester-note">
          <template v-if="!auth.globalTesterMode">
            Tester perks are currently OFF for everyone — a GM has "Global Tester Mode" disabled in Feature Flags.
          </template>
          <template v-else-if="!auth.user.tester_mode_disabled">
            Tester perks are ON — every title, color and banner is unlocked; switch freely.
          </template>
          <template v-else>
            Tester perks are OFF — previewing as a regular player. Flip this back on any time.
          </template>
        </p>
        <label class="toggle-switch">
          <input type="checkbox" aria-label="Toggle tester mode" :checked="!auth.user.tester_mode_disabled" @change="toggleTesterMode" />
          <span class="toggle-switch__track"><span class="toggle-switch__knob"></span></span>
        </label>
      </div>
      <p v-if="testerMessage" class="support-card__message">{{ testerMessage }}</p>

      <div class="support-card">
        <h3 class="ox support-card__title">Contact Support</h3>
        <p v-if="ticketMessage" class="support-card__message">{{ ticketMessage }}</p>
        <input v-model="ticketForm.subject" placeholder="Subject" class="support-card__input" />
        <textarea v-model="ticketForm.body" rows="3" placeholder="Describe the issue…" class="support-card__textarea"></textarea>
        <button @click="submitTicket" class="settings-action-btn">Submit Ticket</button>

        <div v-if="tickets.length" class="support-card__history">
          <div class="support-card__history-label">YOUR TICKETS</div>
          <div v-for="t in tickets" :key="t.id" class="ticket-thread">
            <button type="button" class="support-ticket-row support-ticket-row--btn" @click="toggleTicket(t)">
              <span class="support-ticket-row__subject">{{ t.subject }}</span>
              <span class="support-ticket-row__status">{{ t.status }}</span>
            </button>
            <div v-if="expandedTicket === t.id" class="ticket-thread__body">
              <div class="ticket-thread__messages">
                <div class="ticket-thread__msg ticket-thread__msg--original">
                  <span class="ticket-thread__msg-sender">You</span>
                  <span class="ticket-thread__msg-body">{{ t.body }}</span>
                </div>
                <div
                  v-for="m in t.messages"
                  :key="m.id"
                  class="ticket-thread__msg"
                  :class="{ 'ticket-thread__msg--gm': m.sender && m.sender.role !== 'player' }"
                >
                  <span class="ticket-thread__msg-sender">{{ m.sender && ['gm', 'owner'].includes(m.sender.role) ? `${m.sender.name} (GM)` : 'You' }}</span>
                  <span class="ticket-thread__msg-body">{{ m.body }}</span>
                </div>
              </div>
              <p v-if="replyMessage" class="support-card__message">{{ replyMessage }}</p>
              <div v-if="t.status !== 'closed'" class="ticket-thread__reply">
                <input
                  v-model="replyBody"
                  placeholder="Write a reply…"
                  class="support-card__input"
                  @keyup.enter="sendReply(t)"
                />
                <button type="button" class="settings-action-btn" @click="sendReply(t)">Send</button>
              </div>
              <p v-else class="ticket-thread__closed-note">This ticket is closed.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./SettingsPage.scss" scoped></style>
