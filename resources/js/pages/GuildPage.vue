<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import { useCharacterStore } from '../stores/character';
import MentionInput from '../components/MentionInput.vue';
import { renderChatBody, mentionsMe } from '../chatMentions';

const characterStore = useCharacterStore();
const guild = ref(null);
const myRole = ref('');
const browse = ref([]);
const message = ref('');
const chatBody = ref('');
const createForm = ref({ name: '', tag: '' });
const bankForm = ref({ currency: 'gold', amount: '' });

const mentionCandidates = computed(
  () => guild.value?.members?.map((m) => ({ id: m.character.id, name: m.character.name })) ?? []
);

const myCharacterId = computed(() => characterStore.character?.id);
const canWithdraw = computed(() => myRole.value === 'officer' || myRole.value === 'master');
const canManage = computed(() => myRole.value === 'officer' || myRole.value === 'master');

async function load() {
  const { data } = await api.get('/guild');
  guild.value = data.guild;
  myRole.value = data.my_role || '';
  browse.value = data.browse || [];
  if (!characterStore.character) await characterStore.fetch();
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

async function deposit() {
  message.value = '';
  try {
    await api.post(`/guild/${guild.value.id}/deposit`, { currency: bankForm.value.currency, amount: Number(bankForm.value.amount) });
    bankForm.value.amount = '';
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not deposit.';
  }
}

async function withdraw() {
  message.value = '';
  try {
    await api.post(`/guild/${guild.value.id}/withdraw`, { currency: bankForm.value.currency, amount: Number(bankForm.value.amount) });
    bankForm.value.amount = '';
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not withdraw.';
  }
}

async function promote(member, role) {
  message.value = '';
  try {
    await api.post(`/guild/${guild.value.id}/members/${member.character_id}/promote`, { role });
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not change role.';
  }
}

async function kick(member) {
  if (!confirm(`Kick ${member.character?.name} from the guild?`)) return;
  message.value = '';
  try {
    await api.post(`/guild/${guild.value.id}/members/${member.character_id}/kick`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not kick.';
  }
}

async function leave() {
  if (!confirm('Leave this guild?')) return;
  message.value = '';
  try {
    await api.post(`/guild/${guild.value.id}/leave`);
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not leave.';
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="guild-header">
      <div class="guild-header__icon">🛡</div>
      <h1 class="ox guild-title">Guild</h1>
    </div>

    <p v-if="message" class="guild-message">{{ message }}</p>

    <div v-if="guild" class="guild-panel">
      <h2 class="ox guild-name">{{ guild.name }} [{{ guild.tag }}]</h2>
      <div class="guild-member-count">{{ guild.members?.length || 0 }} / {{ guild.member_cap }} members</div>

      <div class="guild-bank">
        <div class="ox guild-bank__title">Guild Bank</div>
        <div class="guild-bank__balances">{{ guild.bank_gold }}g · {{ guild.bank_gems }}◆</div>
        <div class="guild-bank-row">
          <select v-model="bankForm.currency" class="guild-bank-select">
            <option value="gold">Gold</option>
            <option value="gems">Gems</option>
          </select>
          <input v-model="bankForm.amount" type="number" min="1" placeholder="Amount" class="guild-bank-input" />
          <button @click="deposit" class="guild-bank-btn">Deposit</button>
          <button v-if="canWithdraw" @click="withdraw" class="guild-bank-btn guild-bank-btn--withdraw">Withdraw</button>
        </div>
      </div>

      <div class="guild-members-list">
        <div
          v-for="m in guild.members"
          :key="m.id"
          class="guild-member-row"
        >
          <span class="guild-member-chip">
            <span :style="{ color: m.character?.active_color?.value }">{{ m.character?.name }}</span>
            <span class="guild-member-chip__role">· {{ m.role }}</span>
          </span>
          <span v-if="canManage && m.character_id !== myCharacterId" class="guild-member-actions">
            <button
              v-if="myRole === 'master' && m.role !== 'officer'"
              @click="promote(m, 'officer')"
              class="guild-member-action-btn"
            >Make officer</button>
            <button
              v-if="myRole === 'master' && m.role !== 'member'"
              @click="promote(m, 'member')"
              class="guild-member-action-btn"
            >Demote</button>
            <button
              v-if="myRole === 'master'"
              @click="promote(m, 'master')"
              class="guild-member-action-btn"
            >Transfer ownership</button>
            <button @click="kick(m)" class="guild-member-action-btn guild-member-action-btn--kick">Kick</button>
          </span>
        </div>
      </div>

      <button @click="leave" class="guild-leave-btn">Leave guild</button>

      <div class="guild-chat">
        <div
          v-for="m in [...(guild.messages || [])].reverse()"
          :key="m.id"
          class="guild-chat__line"
          :class="{ 'is-mention-me': mentionsMe(m.body, characterStore.character?.name) }"
        >
          <strong :style="{ color: m.character?.active_color?.value }">{{ m.character?.name }}:</strong>
          <span v-html="renderChatBody(m.body, characterStore.character?.name)"></span>
        </div>
        <div v-if="!guild.messages?.length" class="guild-chat__empty">No messages yet.</div>
      </div>
      <div class="guild-chat-input-row">
        <MentionInput
          v-model="chatBody"
          :candidates="mentionCandidates"
          placeholder="Message the guild…"
          class="guild-chat-input"
          @enter="send"
        />
        <button @click="send" class="guild-chat-send">Send</button>
      </div>
    </div>

    <div v-else class="guild-browse-panel">
      <div class="guild-create-card">
        <h3 class="ox guild-create-card__title">Start a guild</h3>
        <div class="guild-create-row">
          <input v-model="createForm.name" placeholder="Guild name" class="guild-create-input" />
          <input v-model="createForm.tag" maxlength="5" placeholder="TAG" class="guild-create-input guild-create-input--tag" />
          <button @click="create" class="guild-create-btn">Create</button>
        </div>
      </div>

      <h3 class="ox guild-browse-heading">Browse guilds</h3>
      <div class="guild-browse-list">
        <div
          v-for="g in browse"
          :key="g.id"
          class="guild-browse-row"
        >
          <span class="ox guild-browse-row__name">{{ g.name }} [{{ g.tag }}]</span>
          <span class="guild-browse-row__count">{{ g.members_count }} / {{ g.member_cap }}</span>
          <button @click="join(g)" class="guild-browse-row__join">Join</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./GuildPage.scss" scoped></style>
