<script setup>
import { ref, computed, onMounted } from 'vue';
import api from '../api/client';
import Skeleton from '../components/Skeleton.vue';

const data = ref(null);
const loading = ref(true);
const copied = ref(false);
const codeCopied = ref(false);

async function load() {
  loading.value = true;
  try {
    const { data: res } = await api.get('/referrals');
    data.value = res;
  } finally {
    loading.value = false;
  }
}

// Fire-and-forget — a failed ping shouldn't block the clipboard copy the player actually asked for.
function trackCopy() {
  api.post('/referrals/copy').catch(() => {});
}

async function copyLink() {
  await navigator.clipboard.writeText(data.value.invite_url);
  copied.value = true;
  trackCopy();
  setTimeout(() => (copied.value = false), 2000);
}

async function copyCode() {
  await navigator.clipboard.writeText(data.value.code);
  codeCopied.value = true;
  trackCopy();
  setTimeout(() => (codeCopied.value = false), 2000);
}

const progressPct = computed(() => {
  if (!data.value) return 0;
  return (data.value.progress_to_next / data.value.referrals_per_reward) * 100;
});

const ownProgressPct = computed(() => {
  if (!data.value?.referred_by) return 0;
  return Math.min(100, (data.value.referred_by.own_level / data.value.required_level) * 100);
});

function timeAgo(isoString) {
  const seconds = Math.max(0, Math.round((Date.now() - new Date(isoString).getTime()) / 1000));
  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

onMounted(load);
</script>

<template>
  <div class="referrals-page">
    <div class="referrals-header">
      <div class="referrals-header__icon">🎁</div>
      <h1 class="ox referrals-title">Invite Friends</h1>
      <p class="referrals-header__subtitle">
        Every {{ data?.referrals_per_reward ?? 2 }} friends who reach level {{ data?.required_level ?? 5 }} earns you
        {{ data?.reward_vip_days ?? 7 }} days of {{ data?.reward_vip_tier === 'gold' ? 'Gold' : data?.reward_vip_tier }} VIP, and your friend gets {{ data?.referee_bonus_gems ?? 500 }} gems when they reach level {{ data?.required_level ?? 5 }}.
      </p>
    </div>

    <div v-if="loading" class="referrals-skeleton">
      <Skeleton height="120px" />
      <Skeleton height="200px" />
    </div>
    <template v-else-if="data">
      <div v-if="data.referred_by" class="referred-by-card">
        <div class="referred-by-card__head">
          <span class="referred-by-card__icon">🤝</span>
          <span>You were referred by <strong>{{ data.referred_by.name }}</strong></span>
        </div>
        <template v-if="data.referred_by.bonus_claimed">
          <div class="referred-by-card__claimed">✓ You've claimed your {{ data.referee_bonus_gems }} gem bonus</div>
        </template>
        <template v-else>
          <div class="referred-by-card__progress-head">
            <span>Reach level {{ data.required_level }} to earn {{ data.referee_bonus_gems }} gems</span>
            <span class="referred-by-card__progress-count">Lv.{{ data.referred_by.own_level }} / {{ data.required_level }}</span>
          </div>
          <div class="progress-card__track">
            <div class="progress-card__fill" :style="{ width: ownProgressPct + '%' }"></div>
          </div>
        </template>
      </div>

      <div class="invite-card">
        <label for="invite-url" class="invite-card__label">Your invite link</label>
        <div class="invite-card__row">
          <input id="invite-url" class="invite-card__input" :value="data.invite_url" readonly @click="$event.target.select()" />
          <button class="invite-card__copy" @click="copyLink">{{ copied ? 'Copied!' : 'Copy' }}</button>
        </div>
        <div class="invite-card__code">
          Or share your code: <strong>{{ data.code }}</strong>
          <button class="invite-card__copy-code" @click="copyCode">{{ codeCopied ? 'Copied!' : 'Copy code' }}</button>
        </div>
      </div>

      <div class="progress-card">
        <div class="progress-card__head">
          <span class="progress-card__label">Progress to next reward</span>
          <span class="progress-card__count">{{ data.progress_to_next }} / {{ data.referrals_per_reward }}</span>
        </div>
        <div class="progress-card__track">
          <div class="progress-card__fill" :style="{ width: progressPct + '%' }"></div>
        </div>
        <div v-if="data.rewards_claimed > 0" class="progress-card__claimed">
          🎉 {{ data.rewards_claimed }} reward{{ data.rewards_claimed > 1 ? 's' : '' }} claimed so far
        </div>
      </div>

      <div class="referred-list">
        <div class="referred-list__eyebrow">YOUR REFERRALS ({{ data.referred.length }})</div>
        <div v-for="friend in data.referred" :key="friend.name + friend.joined_at" class="referred-row">
          <div class="referred-row__left">
            <span class="referred-row__name">{{ friend.name }}</span>
            <span class="referred-row__joined">joined {{ timeAgo(friend.joined_at) }}</span>
          </div>
          <span class="referred-row__status" :class="{ 'referred-row__status--qualified': friend.qualified }">
            {{ friend.qualified ? `✓ Level ${friend.level}` : `Level ${friend.level} / ${data.required_level}` }}
          </span>
        </div>
        <p v-if="!data.referred.length" class="referred-empty">
          Nobody's signed up with your link yet — share it to start earning rewards.
        </p>
      </div>
    </template>
  </div>
</template>

<style lang="scss" src="./ReferralsPage.scss" scoped></style>
