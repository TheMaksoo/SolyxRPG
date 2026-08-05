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

function getMilestoneSample() {
  const levels = [10, 25, 50];
  return levels.join(', ') + ' …';
}

onMounted(load);
</script>

<template>
  <div class="referrals-page">
    <div class="referrals-header">
      <div class="referrals-header__icon">🎁</div>
      <h1 class="ox referrals-title">Invite Friends</h1>
      <p class="referrals-header__subtitle">
        Share your link with as many people as you like — there's no limit.
        Rewards unlock based on how far your friends progress, explained below.
      </p>
    </div>

    <div class="rewards-explainer">
      <div class="rewards-explainer__section">
        <div class="rewards-explainer__icon">👑</div>
        <div class="rewards-explainer__body">
          <strong>You (the referrer) get:</strong>
          <ul class="rewards-explainer__list">
            <li>
              {{ data?.reward_vip_days ?? 7 }} days of
              {{ data?.reward_vip_tier === 'gold' ? 'Gold' : (data?.reward_vip_tier ?? 'Gold') }} Rank
              for every {{ data?.referrals_per_reward ?? 2 }} friends who reach level {{ data?.required_level ?? 5 }}.
              This reward repeats — every {{ data?.referrals_per_reward ?? 2 }} qualifying friends earns another {{ data?.reward_vip_days ?? 7 }} days.
            </li>
            <li>
              {{ data?.milestone_gem_reward ?? 100 }} gems for every {{ data?.referrals_per_reward ?? 2 }} friends who reach
              each milestone level ({{ data ? getMilestoneSample() : '10, 25, 50 …' }}).
              Repeats every {{ data?.referrals_per_reward ?? 2 }} friends per milestone — no cap.
            </li>
          </ul>
        </div>
      </div>
      <div class="rewards-explainer__section">
        <div class="rewards-explainer__icon">🎮</div>
        <div class="rewards-explainer__body">
          <strong>Your friends (the referred) get:</strong>
          <ul class="rewards-explainer__list">
            <li>{{ data?.referee_bonus_gems ?? 500 }} gems when they reach level {{ data?.required_level ?? 5 }} for the first time.</li>
            <li>{{ data?.referee_milestone_gem_reward ?? 20 }} gems each time they personally reach a milestone level.</li>
          </ul>
        </div>
      </div>
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
        <label for="invite-url" class="invite-card__label">Your invite link — share it freely, no limit!</label>
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
          <span class="progress-card__label">
            Friends at level {{ data.required_level }}+ ({{ data.referrals_per_reward }} = 1 VIP reward)
          </span>
          <span class="progress-card__count">{{ data.progress_to_next }} / {{ data.referrals_per_reward }}</span>
        </div>
        <div class="progress-card__track">
          <div class="progress-card__fill" :style="{ width: progressPct + '%' }"></div>
        </div>
        <div v-if="data.rewards_claimed > 0" class="progress-card__claimed">
          🎉 {{ data.rewards_claimed }} VIP reward{{ data.rewards_claimed > 1 ? 's' : '' }} claimed so far — keep inviting for more!
        </div>
      </div>

      <div v-if="data.milestone_progress && data.milestone_progress.length > 0" class="milestone-section">
        <div class="milestone-section__header">
          <div class="milestone-section__icon">💎</div>
          <h2 class="milestone-section__title">Level Milestone Rewards</h2>
          <p class="milestone-section__subtitle">
            You earn {{ data.milestone_gem_reward }} gems for every {{ data.referrals_per_reward }} friends reaching each milestone.
            Your referred friends each earn {{ data.referee_milestone_gem_reward }} gems when they personally hit a milestone.
            Both rewards repeat with no cap — the more you invite, the more everyone earns.
          </p>
        </div>

        <div class="milestone-list">
          <div v-for="milestone in data.milestone_progress" :key="milestone.level" class="milestone-card">
            <div class="milestone-card__header">
              <span class="milestone-card__level">Level {{ milestone.level }}</span>
              <span class="milestone-card__reward">
                {{ milestone.reward_type === 'vip' ? `${milestone.reward_amount} days ${data.reward_vip_tier} VIP` : `${milestone.reward_amount} gems` }}
                <span class="milestone-card__reward-note">per {{ data.referrals_per_reward }} friends · your friends get {{ data.referee_milestone_gem_reward }} gems each</span>
              </span>
            </div>
            <div class="milestone-card__body">
              <div class="milestone-card__progress-head">
                <span class="milestone-card__progress-label">Friends at this level ({{ data.referrals_per_reward }} = reward)</span>
                <span class="milestone-card__progress-count">{{ milestone.progress }} / {{ data.referrals_per_reward }}</span>
              </div>
              <div class="milestone-card__track">
                <div class="milestone-card__fill" :style="{ width: (milestone.progress / data.referrals_per_reward * 100) + '%' }"></div>
              </div>
              <div class="milestone-card__stats">
                <span class="milestone-card__stat">
                  {{ milestone.qualifying_count }} friend{{ milestone.qualifying_count !== 1 ? 's' : '' }} reached this level
                </span>
                <span v-if="milestone.rewards_claimed > 0" class="milestone-card__claimed">
                  ✓ {{ milestone.rewards_claimed }} reward{{ milestone.rewards_claimed > 1 ? 's' : '' }} claimed
                </span>
              </div>
            </div>
          </div>
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
