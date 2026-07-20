<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import { useAuthStore } from '../stores/auth';
import MentionInput from '../components/MentionInput.vue';
import { renderChatBody, mentionsMe } from '../chatMentions';

const characterStore = useCharacterStore();
const auth = useAuthStore();
const party = ref(null);
const partyBonuses = ref(null);
const invites = ref([]);
const friends = ref([]);
const message = ref('');
const busy = ref(false);
const chatBody = ref('');

const mentionCandidates = computed(
  () => party.value?.members?.map((m) => ({ id: m.character.id, name: m.character.name })) ?? []
);

const BONUS_LABELS = {
  atk_pct: (v) => `+${v}% ATK`,
  def_pct: (v) => `+${v}% DEF`,
  mp_pct: (v) => `+${v}% Max Mana`,
  crit_chance: (v) => `+${v}% Crit Chance`,
  luck: (v) => `+${v} Luck`,
};

const activeBonuses = computed(() => {
  if (!partyBonuses.value) return [];
  return Object.entries(partyBonuses.value)
    .filter(([, v]) => v > 0)
    .map(([key, v]) => BONUS_LABELS[key]?.(v) ?? `${key}: ${v}`);
});

// Friends already in a party get filtered out server-side with a clear error if invited anyway —
// we don't have per-friend party status here, so the invite button is always shown and the API's
// message (e.g. "already in a party") surfaces if it turns out they're unavailable.
const invitableFriends = computed(() => friends.value);

async function load() {
  const [partyRes, friendsRes] = await Promise.all([api.get('/party'), api.get('/friends')]);
  party.value = partyRes.data.party;
  partyBonuses.value = partyRes.data.party_bonuses;
  invites.value = partyRes.data.invites;
  friends.value = friendsRes.data.friends;
}

async function createParty() {
  message.value = '';
  busy.value = true;
  try {
    await api.post('/party');
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not create party.';
  } finally {
    busy.value = false;
  }
}

async function invite(character) {
  message.value = '';
  try {
    await api.post(`/party/invite/${character.id}`);
    message.value = `Invited ${character.name}.`;
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not invite.';
  }
}

async function acceptInvite(invite) {
  message.value = '';
  try {
    await api.post(`/party/invites/${invite.id}/accept`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not accept invite.';
  }
}

async function declineInvite(invite) {
  await api.post(`/party/invites/${invite.id}/decline`);
  await load();
}

async function leaveParty() {
  message.value = '';
  busy.value = true;
  try {
    await api.post('/party/leave');
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not leave party.';
  } finally {
    busy.value = false;
  }
}

async function sendChat() {
  if (!chatBody.value.trim()) return;
  await api.post('/party/message', { body: chatBody.value.trim() });
  chatBody.value = '';
  await load();
}

async function kick(character) {
  message.value = '';
  try {
    await api.post(`/party/kick/${character.id}`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not kick.';
  }
}

const isLeader = computed(() => party.value && characterStore.character && party.value.leader_character_id === characterStore.character.id);

onMounted(() => {
  load();
  if (!characterStore.character) characterStore.fetch();
});
</script>

<template>
  <div>
    <div class="party-header">
      <div class="party-header__icon">🧑‍🧑‍🧒</div>
      <h1 class="ox party-title">Party</h1>
    </div>
    <p class="party-subtitle">
      Team up with up to 4 friends — every distinct class present buffs the whole party, and fighting in the
      same zone shares a cut of gold, XP, and gems with whoever's online alongside you.
    </p>

    <p v-if="message" class="party-message">{{ message }}</p>

    <template v-if="!party">
      <button class="party-create-btn" @click="createParty" :disabled="busy">+ Start a Party</button>

      <div v-if="invites.length" class="party-invites">
        <div class="party-invites__eyebrow">PARTY INVITES</div>
        <div v-for="inv in invites" :key="inv.id" class="party-invite-row">
          <span class="party-invite-row__text">
            <strong>{{ inv.inviter?.name }}</strong> invited you to join
            <strong>{{ inv.party?.leader?.name }}'s</strong> party
          </span>
          <div class="party-invite-row__actions">
            <button @click="acceptInvite(inv)" class="party-invite-row__accept">Accept</button>
            <button @click="declineInvite(inv)" class="party-invite-row__decline">Decline</button>
          </div>
        </div>
      </div>
    </template>

    <template v-else>
      <div class="party-card">
        <div class="party-card__header">
          <div class="ox party-card__title">{{ party.leader?.name }}'s Party</div>
          <span class="party-card__size">{{ party.members.length }} / 4</span>
        </div>

        <div class="party-roster">
          <div v-for="m in party.members" :key="m.id" class="party-member">
            <div class="party-member__info">
              <span class="ox party-member__name" :style="{ color: m.character?.active_color?.value }">{{ m.character?.name }}</span>
              <span v-if="m.character_id === party.leader_character_id" class="party-member__leader-badge">LEADER</span>
              <span class="party-member__meta">{{ m.character?.base_class }} · Lv.{{ m.character?.level }}</span>
            </div>
            <button
              v-if="isLeader && m.character_id !== characterStore.character?.id"
              class="party-member__kick"
              @click="kick(m.character)"
            >
              Kick
            </button>
          </div>
        </div>

        <div v-if="activeBonuses.length" class="party-bonuses">
          <div class="party-bonuses__eyebrow">ACTIVE PARTY BONUSES</div>
          <div class="party-bonuses__list">
            <span v-for="b in activeBonuses" :key="b" class="party-bonus-chip">{{ b }}</span>
          </div>
        </div>

        <button class="party-leave-btn" @click="leaveParty" :disabled="busy">
          {{ isLeader ? 'Disband Party' : 'Leave Party' }}
        </button>

        <div class="party-chat">
          <div
            v-for="m in [...(party.messages || [])].reverse()"
            :key="m.id"
            class="party-chat__line"
            :class="{ 'is-mention-me': auth.user?.preferences?.highlight_mentions !== false && mentionsMe(m.body, characterStore.character?.name) }"
          >
            <strong :style="{ color: m.character?.active_color?.value }">{{ m.character?.name }}:</strong>
            <span v-html="renderChatBody(m.body, characterStore.character?.name, mentionCandidates.map((c) => c.name))"></span>
          </div>
          <div v-if="!party.messages?.length" class="party-chat__empty">No messages yet.</div>
        </div>
        <div class="party-chat-input-row">
          <MentionInput
            v-model="chatBody"
            :candidates="mentionCandidates"
            placeholder="Message the party…"
            class="party-chat-input"
            @enter="sendChat"
          />
          <button @click="sendChat" class="party-chat-send">Send</button>
        </div>
      </div>

      <div v-if="isLeader" class="party-invite-panel">
        <div class="party-invite-panel__eyebrow">INVITE A FRIEND</div>
        <div v-if="!invitableFriends.length" class="party-invite-panel__empty">
          No friends available to invite — everyone's already in a party, or you have none yet.
        </div>
        <div v-for="row in invitableFriends" :key="row.character.id" class="party-invite-candidate">
          <span class="party-invite-candidate__name">{{ row.character.name }} <span class="party-invite-candidate__meta">· {{ row.character.base_class }} Lv.{{ row.character.level }}</span></span>
          <button @click="invite(row.character)" class="party-invite-candidate__btn">Invite</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./PartyPage.scss" scoped></style>
