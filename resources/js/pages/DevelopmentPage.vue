<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';

const auth = useAuthStore();

const entries = ref([]);
const loading = ref(true);
const bugFormOpen = ref({}); // changelog_id -> boolean
const bugForm = ref({});     // changelog_id -> { title, description }
const bugMessage = ref({});  // changelog_id -> string
const voteLoading = ref({});
const bugSubmitting = ref({});

const isTester = computed(() => auth.user?.is_tester || auth.user?.role === 'tester' || auth.user?.role === 'gm' || auth.user?.role === 'owner');
const isGm = computed(() => auth.user?.role === 'gm' || auth.user?.role === 'owner');

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get('/development');
    entries.value = data.entries;
    // Initialise bug form state for each entry.
    data.entries.forEach((e) => {
      bugForm.value[e.id] = { title: '', description: '' };
    });
  } finally {
    loading.value = false;
  }
}

async function toggleVote(entry) {
  if (voteLoading.value[entry.id]) return;
  voteLoading.value[entry.id] = true;
  try {
    const { data } = await api.post(`/development/changelog/${entry.id}/vote`);
    entry.user_voted = data.voted;
    entry.votes_count = data.votes_count;
  } finally {
    voteLoading.value[entry.id] = false;
  }
}

function openBugForm(entry) {
  bugFormOpen.value[entry.id] = true;
  bugMessage.value[entry.id] = '';
}

function closeBugForm(entry) {
  bugFormOpen.value[entry.id] = false;
  bugMessage.value[entry.id] = '';
  bugForm.value[entry.id] = { title: '', description: '' };
}

async function submitBug(entry) {
  if (bugSubmitting.value[entry.id]) return;
  const form = bugForm.value[entry.id];
  if (!form.title.trim() || !form.description.trim()) {
    bugMessage.value[entry.id] = 'Please fill in a title and description.';
    return;
  }
  bugSubmitting.value[entry.id] = true;
  bugMessage.value[entry.id] = '';
  try {
    await api.post(`/development/changelog/${entry.id}/report-bug`, form);
    bugMessage.value[entry.id] = '✓ Bug report submitted!';
    entry.bug_reports_count = (entry.bug_reports_count ?? 0) + 1;
    setTimeout(() => closeBugForm(entry), 1800);
  } catch (e) {
    bugMessage.value[entry.id] = e.response?.data?.message || 'Could not submit bug report.';
  } finally {
    bugSubmitting.value[entry.id] = false;
  }
}

const TAG_LABEL = { feature: 'New', fix: 'Fix', balance: 'Balance', misc: 'Misc' };

function formatDate(isoString) {
  return new Date(isoString).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

onMounted(load);
</script>

<template>
  <div class="dev-page">

    <!-- Header -->
    <div class="dev-header">
      <div class="dev-header__icon">⚗</div>
      <div class="dev-header__text">
        <h1 class="ox dev-header__title">Development Preview</h1>
        <p class="dev-header__subtitle">
          You're on the <strong>test build</strong>. Vote on updates and report bugs before they go live.
        </p>
      </div>
      <div v-if="isTester" class="dev-header__badge">
        <span class="dev-tester-badge">🧪 Tester</span>
      </div>
    </div>

    <!-- Apply CTA for non-testers -->
    <div v-if="!isTester && !auth.user?.tester_applied_at" class="dev-apply-card">
      <div class="dev-apply-card__icon">🧪</div>
      <div class="dev-apply-card__body">
        <p class="dev-apply-card__title">Want to help test?</p>
        <p class="dev-apply-card__text">
          Testers get early access to new features and can vote on updates and file bug reports.
        </p>
      </div>
      <a class="dev-apply-card__btn" href="https://discord.gg/WhCmHm3KaS" target="_blank" rel="noopener">
        Apply on Discord
      </a>
    </div>

    <div v-else-if="!isTester && auth.user?.tester_applied_at" class="dev-apply-card dev-apply-card--pending">
      <div class="dev-apply-card__icon">⏳</div>
      <div class="dev-apply-card__body">
        <p class="dev-apply-card__title">Application Pending</p>
        <p class="dev-apply-card__text">Your tester application is awaiting GM review.</p>
      </div>
    </div>

    <!-- Updates list -->
    <div class="dev-section">
      <h2 class="dev-section__title">Updates</h2>

      <div v-if="loading" class="dev-skeleton">
        <Skeleton height="110px" :count="3" />
      </div>

      <div v-else-if="!entries.length" class="dev-empty">No updates yet.</div>

      <div v-else class="dev-list">
        <div
          v-for="entry in entries"
          :key="entry.id"
          class="dev-entry"
          :class="{ 'dev-entry--live': entry.pushed_live_at }"
        >
          <!-- Entry head -->
          <div class="dev-entry__head">
            <span class="dev-entry__version">v{{ entry.version }}</span>
            <span class="dev-entry__tag" :class="`dev-entry__tag--${entry.tag}`">{{ TAG_LABEL[entry.tag] ?? entry.tag }}</span>
            <span class="ox dev-entry__title">{{ entry.title }}</span>
            <div class="dev-entry__meta">
              <span v-if="entry.visibility !== 'player'" class="dev-entry__vis" :class="`dev-entry__vis--${entry.visibility}`">
                {{ entry.visibility === 'gm' ? '🔒 GM only' : '🧪 Tester+' }}
              </span>
              <span class="dev-entry__date">{{ formatDate(entry.published_at) }}</span>
              <span v-if="entry.pushed_live_at" class="dev-entry__live-badge">✓ Live</span>
            </div>
          </div>

          <p class="dev-entry__body">{{ entry.body }}</p>

          <!-- Stats row -->
          <div class="dev-entry__stats">
            <span class="dev-entry__stat">👍 {{ entry.votes_count }} vote{{ entry.votes_count !== 1 ? 's' : '' }}</span>
            <span class="dev-entry__stat">🐞 {{ entry.bug_reports_count }} bug{{ entry.bug_reports_count !== 1 ? 's' : '' }}</span>
          </div>

          <!-- Tester actions — only if approved tester / GM, and entry not pushed live yet -->
          <div v-if="isTester" class="dev-entry__actions">
            <button
              class="dev-btn"
              :class="entry.user_voted ? 'dev-btn--voted' : 'dev-btn--vote'"
              :disabled="voteLoading[entry.id]"
              @click="toggleVote(entry)"
            >
              {{ entry.user_voted ? '✓ Voted' : '👍 Looks Good' }}
            </button>

            <button
              v-if="!bugFormOpen[entry.id]"
              class="dev-btn dev-btn--bug"
              @click="openBugForm(entry)"
            >
              🐞 Report Bug
            </button>
          </div>

          <!-- Bug form -->
          <div v-if="bugFormOpen[entry.id]" class="dev-bug-form">
            <input
              v-model="bugForm[entry.id].title"
              placeholder="Bug title"
              class="dev-bug-form__input"
              maxlength="200"
            />
            <textarea
              v-model="bugForm[entry.id].description"
              placeholder="Describe what went wrong…"
              class="dev-bug-form__textarea"
              rows="3"
              maxlength="2000"
            />
            <p v-if="bugMessage[entry.id]" class="dev-bug-form__message">{{ bugMessage[entry.id] }}</p>
            <div class="dev-bug-form__row">
              <button class="dev-btn dev-btn--submit" :disabled="bugSubmitting[entry.id]" @click="submitBug(entry)">
                Submit Report
              </button>
              <button class="dev-btn dev-btn--cancel" @click="closeBugForm(entry)">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style lang="scss" src="./DevelopmentPage.scss" scoped></style>
