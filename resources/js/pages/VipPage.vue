<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import api from '../api/client';
import { formatCents } from '../currency';
import { useCharacterStore } from '../stores/character';
import Toast from '../components/Toast.vue';

const store = useCharacterStore();
const founderPreviewName = computed(() => store.character?.name || 'You');
const founderPreviewInitials = computed(() => founderPreviewName.value.slice(0, 2).toUpperCase());

const info = ref(null);
const message = ref('');
let errorToastTimer = null;
watch(message, (val) => {
  clearTimeout(errorToastTimer);
  if (val) errorToastTimer = setTimeout(() => { message.value = ''; }, 5000);
});
const period = ref('month'); // 'month' | 'year' — yearly is ~20% off, see VipController::YEARLY_PRICE_CENTS

// Which tier cards have their full perk list expanded — collapsed by default so the page reads as 3
// compact, comparable cards instead of a wall of text; per-card, so comparing two tiers' full lists
// side by side is still possible.
const expandedTiers = ref(new Set());
function toggleExpanded(key) {
  const next = new Set(expandedTiers.value);
  next.has(key) ? next.delete(key) : next.add(key);
  expandedTiers.value = next;
}

const TIER_RANK = { none: 0, bronze: 1, gold: 2, diamond: 3 };

function tierAction(key) {
  if (info.value?.vip_lifetime) return 'current';
  const current = info.value?.vip_tier ?? 'none';
  if (key === current) return 'current';
  if (current === 'none') return 'subscribe';
  return TIER_RANK[key] > TIER_RANK[current] ? 'upgrade' : 'downgrade';
}

function tierButtonLabel(key) {
  if (info.value?.vip_lifetime) return 'Included via Lifetime';
  const action = tierAction(key);
  return { current: 'Current Plan', subscribe: 'Subscribe', upgrade: 'Upgrade', downgrade: 'Downgrade' }[action];
}

// Cosmetic/QoL perks that aren't part of the numeric tiers payload — every VIP tier grants both,
// confirmed against AdBanner.vue (gates on vip_tier !== 'none') and VipBadge.vue (renders per tier).
const COSMETIC_PERKS = {
  bronze: ['Ad-free experience', 'Bronze Rank name badge'],
  gold: ['Ad-free experience', 'Gold Rank name badge'],
  diamond: ['Ad-free experience', 'Diamond Rank name badge'],
};

// The handful of bonuses that matter most at a glance — shown on every tier card whether it's expanded
// or not, so a player can compare tiers without opening anything. The rest (daily limits, auto-pass,
// crafting, roster, ...) is real but secondary, and lives behind the "Full details" toggle below.
const HEADLINE_PERKS = [
  (tier) => `+${tier.gold_xp_pct_bonus}% gold & XP from every battle`,
  (tier) => `+${tier.monthly_gems} gems every month, free`,
  (tier) => `${tier.market_fee_reduction_pct}% lower Marketplace fees`,
];

// Grouped, ordered perk sections. Each entry maps a tiers[key] field to a display line.
// Grouping mirrors the real mechanical categories in User.php's VIP_TIER_* consts so this list
// stays a 1:1 mirror of what's actually implemented server-side, not aspirational copy.
const PERK_SECTIONS = [
  {
    title: 'Daily Login',
    perks: [
      (tier) => `+${tier.daily_gem_bonus} bonus gem${tier.daily_gem_bonus > 1 ? 's' : ''} every time you claim your daily reward`,
    ],
  },
  {
    title: 'Daily Limits',
    perks: [
      (tier) => `+${tier.pvp_bonus_attempts} daily PvP battle attempts`,
      (tier) => `+${tier.dungeon_bonus_attempts} daily dungeon raid attempt${tier.dungeon_bonus_attempts > 1 ? 's' : ''}`,
    ],
  },
  {
    title: 'Economy',
    perks: [
      (tier) => `+${tier.gold_xp_pct_bonus}% gold & XP from battles`,
      (tier) => `+${tier.monthly_gems} gems every month, free`,
      (tier) => `${tier.market_fee_reduction_pct}% lower Marketplace fees: sale fee (base 10% → ${10 - tier.market_fee_reduction_pct}%) and cancel fee (base 10% → ${10 - tier.market_fee_reduction_pct}%)`,
      (tier) => `+${tier.market_listing_bonus} active Marketplace listing${tier.market_listing_bonus > 1 ? 's' : ''} (up to ${10 + tier.market_listing_bonus} total)`,
    ],
  },
  {
    title: 'Auto-Battle & Auto-Gather',
    perks: [
      (tier) => `+${tier.auto_pass_pct_bonus}% bonus duration on gem-purchased passes (e.g. 60m gem pass → ${Math.round(60 * (1 + tier.auto_pass_pct_bonus / 100))}m)`,
      (tier) => `+${tier.gather_speed_pct_bonus}% Auto-Gather action speed`,
      (tier) => `+${tier.gather_yield_pct_bonus}% Auto-Gather item yield per action`,
    ],
  },
  {
    title: 'Loot Crates',
    perks: [
      (tier) => `+${tier.lootbox_extra_slots} concurrent lootbox${tier.lootbox_extra_slots > 1 ? 'es' : ''} unlocking at once (up to ${1 + tier.lootbox_extra_slots} total)`,
      (tier) => `${tier.lootbox_time_reduction_pct}% faster lootbox unlock timers`,
    ],
  },
  {
    title: 'Character Power',
    perks: [
      (tier) => `+${tier.luck_bonus} base Luck`,
      (tier) => `+${tier.regen_flat_bonus} flat and +${tier.regen_pct_bonus}% HP/mana regen rate`,
      (tier) => `+${tier.energy_flat_bonus} flat and +${tier.energy_pct_bonus}% Energy regen rate`,
    ],
  },
  {
    title: 'Crafting',
    perks: [
      (tier) => `${tier.craft_speed_pct_bonus}% faster crafting`,
      (tier) => `+${tier.craft_queue_bonus} crafting queue slot${tier.craft_queue_bonus > 1 ? 's' : ''}`,
    ],
  },
  {
    title: 'Roster & Companions',
    perks: [
      (tier) => `+${tier.slots} character slot${tier.slots > 1 ? 's' : ''} (up to ${1 + tier.slots} total with subscription)`,
      (tier) => `${tier.pet_slots} active companion pet slot${tier.pet_slots > 1 ? 's' : ''}`,
      (tier) => `Companion roster cap of ${tier.tame_roster_cap} tamed companions (base is 5)`,
      (tier) => `+${tier.tame_success_bonus_pct}% higher tame success chance`,
    ],
  },
  {
    title: 'Battle Pass',
    perks: [
      (tier) => `+${tier.bp_xp_pct_bonus}% Battle Pass Points from every quest`,
    ],
  },
];

// Total perk count hidden behind "full details," shown on the collapsed card so it's clear there's
// more than the 3 headline lines without having to open it first.
const EXTRA_PERK_COUNT = PERK_SECTIONS.reduce((sum, section) => sum + section.perks.length, 0);
function extraPerkCount(key) {
  return EXTRA_PERK_COUNT + (COSMETIC_PERKS[key]?.length ?? 0);
}

async function load() {
  const { data } = await api.get('/vip');
  info.value = data;
}

const founder = ref(null);
const purchasingFounder = ref(false);

async function loadFounder() {
  const { data } = await api.get('/founders');
  founder.value = data;
}

async function purchaseFounderPack() {
  message.value = '';
  purchasingFounder.value = true;
  try {
    const { data } = await api.post('/founders/purchase');
    if (data.checkout_url) {
      window.location.href = data.checkout_url;
    }
  } catch (e) {
    message.value = e.response?.data?.message || 'The Founder Beta Pack is unavailable.';
  } finally {
    purchasingFounder.value = false;
  }
}

async function subscribe(tier) {
  message.value = '';
  try {
    const { data } = await api.post('/vip/subscribe', { tier, period: period.value });
    if (data.checkout_url) {
      window.location.href = data.checkout_url;
      return;
    }
    // In-place tier switch — no checkout redirect, the existing subscription was updated directly.
    message.value = `Switched to ${tier} Rank. Your next bill reflects the prorated difference.`;
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Subscriptions unavailable.';
  }
}

const purchasingLifetime = ref(false);

async function purchaseLifetime() {
  message.value = '';
  purchasingLifetime.value = true;
  try {
    const { data } = await api.post('/vip/lifetime');
    if (data.checkout_url) {
      window.location.href = data.checkout_url;
    }
  } catch (e) {
    message.value = e.response?.data?.message || 'Lifetime Rank is unavailable.';
  } finally {
    purchasingLifetime.value = false;
  }
}

const cancelling = ref(false);

async function cancelSubscription() {
  message.value = '';
  cancelling.value = true;
  try {
    await api.post('/vip/cancel');
    message.value = "Subscription cancelled. You'll keep your perks until it runs out, then it won't renew.";
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not cancel.';
  } finally {
    cancelling.value = false;
  }
}

async function resumeSubscription() {
  message.value = '';
  cancelling.value = true;
  try {
    await api.post('/vip/resume');
    message.value = "Subscription resumed. You won't be charged again until your current period runs out.";
    await load();
  } catch (e) {
    message.value = e.response?.data?.message || 'Could not resume.';
  } finally {
    cancelling.value = false;
  }
}

function formatDate(iso) {
  return iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) : '';
}

onMounted(() => {
  load();
  loadFounder();
});
</script>

<template>
  <div>
    <!-- Once you own it, the offer itself is gone — no lingering disabled "you already bought this"
    card, it just stops showing up for you at all, same as any other one-shot purchase would.
    Visual design imported from the Claude Design "Solyx Profile" mock's Founder Beta Pack section —
    same layout/animations, but copy stays honest to how this app's pack actually works: no fixed end
    date (beta itself doesn't have one), no global edition cap, and a real "founders so far" count
    instead of a fabricated one. -->
    <div v-if="founder?.available && !founder?.is_founder" class="vip-founder-card">
      <span class="vip-founder-card__watermark">♛</span>
      <div class="vip-founder-card__top">
        <div class="vip-founder-card__intro">
          <div class="vip-founder-card__pills">
            <span class="vip-founder-card__pill vip-founder-card__pill--gold">FOUNDER BETA PACK</span>
            <span class="vip-founder-card__pill">NEVER SOLD AGAIN</span>
          </div>
          <div class="ox vip-founder-card__title">Become a Founder</div>
          <p class="vip-founder-card__desc">
            Five cosmetics that can never be earned again, plus {{ (founder?.gem_bonus ?? 0).toLocaleString() }} gems and your
            name in the <router-link to="/hall-of-founders" class="vip-founder-card__link">Hall of Founders</router-link>.
            One-time purchase: never sold again once Solyx leaves beta, still ongoing, so it's available now.
          </p>
        </div>
        <div class="vip-founder-card__buy">
          <div v-if="founder" class="vip-founder-card__price">
            <span class="ox vip-founder-card__price-amount">{{ formatCents(founder.price_cents) }}</span>
            <span class="vip-founder-card__price-period">once</span>
          </div>
          <button @click="purchaseFounderPack" class="vip-founder-card__cta" :disabled="purchasingFounder">
            {{ purchasingFounder ? 'Redirecting…' : 'Buy Founder Beta Pack' }}
          </button>
        </div>
      </div>

      <div class="vip-founder-card__grid">
        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">01 · BANNER</span>
            <span class="vip-founder-preview__tag">FOUNDER'S DAWN</span>
          </div>
          <div class="vip-founder-banner-art">
            <span class="vip-founder-banner-art__star" style="left:13%;top:15%;animation-duration:2.6s"></span>
            <span class="vip-founder-banner-art__star vip-founder-banner-art__star--sm" style="left:32%;top:9%;animation-delay:.5s;animation-duration:3.4s"></span>
            <span class="vip-founder-banner-art__star" style="left:61%;top:21%;animation-delay:1.1s;animation-duration:3s"></span>
            <span class="vip-founder-banner-art__star vip-founder-banner-art__star--sm" style="left:79%;top:12%;animation-delay:.8s;animation-duration:2.2s"></span>
            <span class="vip-founder-banner-art__sun"></span>
            <span class="vip-founder-banner-art__texture"></span>
            <span class="vip-founder-banner-art__glow-line"></span>
            <div class="vip-founder-banner-art__crest-row">
              <div class="vip-founder-banner-art__crest">🌅</div>
              <div class="vip-founder-banner-art__mark">
                <span class="ox">FOUNDER</span>
                <span>BETA 2026</span>
              </div>
            </div>
          </div>
          <p class="vip-founder-preview__note">Live sunrise art, drifting stars and a breathing sun. The only animated banner in Solyx.</p>
        </div>

        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">02 · FRAME</span>
            <span class="vip-founder-preview__tag">GILDED FOUNDER</span>
          </div>
          <div class="vip-founder-frame-demo">
            <div class="vip-founder-frame-demo__ring-wrap">
              <div class="vip-founder-frame-demo__ring">
                <span class="ox">{{ founderPreviewInitials }}</span>
              </div>
              <span class="vip-founder-frame-demo__crown">♛</span>
            </div>
            <p>Crowned gold frame that pulses with a slow glow.</p>
          </div>
          <p class="vip-founder-preview__note">Shows on your avatar everywhere: chat, arena, leaderboards.</p>
        </div>

        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">03 · TITLE</span>
            <span class="vip-founder-preview__tag">FOUNDER</span>
          </div>
          <div class="vip-founder-title-demo">
            <div class="vip-founder-title-demo__row">
              <span class="ox vip-founder-title-demo__name">{{ founderPreviewName }}</span>
              <span class="ox vip-founder-title-demo__pill">FOUNDER</span>
            </div>
            <p>Gold-lettered, unique to beta buyers.</p>
          </div>
          <p class="vip-founder-preview__note">Sits beside your name in every list in the game.</p>
        </div>

        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">04 · BADGE</span>
            <span class="vip-founder-preview__tag">FOUNDER BADGE</span>
          </div>
          <div class="vip-founder-badge-demo">
            <div class="vip-founder-badge-demo__hex">🎖️</div>
            <p>Shows next to your name everywhere: chat, leaderboards, profile.</p>
          </div>
          <p class="vip-founder-preview__note">A small gold emblem that marks you as one of the first.</p>
        </div>

        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">05 · NAME COLOR</span>
            <span class="vip-founder-preview__tag">GOLD</span>
          </div>
          <div class="vip-founder-title-demo">
            <div class="vip-founder-title-demo__row">
              <span class="ox vip-founder-title-demo__name vip-founder-title-demo__name--gold">{{ founderPreviewName }}</span>
            </div>
            <p>Your name in gold, everywhere it's shown.</p>
          </div>
          <p class="vip-founder-preview__note">Retired from the Gem Store, gold is exclusive to Founders now.</p>
        </div>

        <div class="vip-founder-preview">
          <div class="vip-founder-preview__head">
            <span class="vip-founder-preview__num">06 · GEMS</span>
            <span class="vip-founder-preview__tag">ONE-TIME</span>
          </div>
          <div class="vip-founder-gems-demo">
            <div class="vip-founder-gems-demo__icon">💎</div>
            <div>
              <div class="ox vip-founder-gems-demo__amount">+{{ (founder?.gem_bonus ?? 0).toLocaleString() }}</div>
              <div v-if="founder?.gem_bonus_value_cents" class="vip-founder-gems-demo__value">
                worth {{ formatCents(founder.gem_bonus_value_cents) }} at Gem Store rates
              </div>
              <p>Credited the moment you buy, spend it anywhere gems are spent.</p>
            </div>
          </div>
          <p class="vip-founder-preview__note">A cash-value bonus on top of the cosmetics, not the point of the pack.</p>
        </div>
      </div>

      <div class="vip-founder-card__footer">
        <span><b class="ox">{{ founder?.founders?.length ?? 0 }}</b> founder{{ (founder?.founders?.length ?? 0) === 1 ? '' : 's' }} so far</span>
        <span>Cosmetic only, no stat or progression advantage</span>
        <span>Applies instantly to this account</span>
      </div>
    </div>

    <div class="vip-header">
      <div class="ox vip-header__title">Solyx Premium</div>
      <p class="vip-header__subtitle">Monthly membership. Boosts, convenience, and cosmetics; cancel anytime.</p>
      <p v-if="info" class="vip-header__current-tier">
        Current rank: <strong>{{ info.vip_tier }}</strong>
      </p>
    </div>

    <Toast :message="message" type="error" />

    <div v-if="info?.has_stripe_subscription && info.vip_tier !== 'none'" class="vip-subscription-status">
      <template v-if="info.vip_cancel_at_period_end">
        <p>
          Your subscription is <strong>cancelled</strong>: you'll keep {{ info.vip_tier }} Rank until
          <strong>{{ formatDate(info.vip_expires_at) }}</strong>, then it won't renew or charge you again.
        </p>
        <button type="button" class="vip-cancel-btn" :disabled="cancelling" @click="resumeSubscription">
          {{ cancelling ? 'Resuming…' : 'Resubscribe' }}
        </button>
      </template>
      <template v-else>
        <p>Renews on <strong>{{ formatDate(info.vip_expires_at) }}</strong>.</p>
        <button type="button" class="vip-cancel-btn" :disabled="cancelling" @click="cancelSubscription">
          {{ cancelling ? 'Cancelling…' : 'Cancel subscription' }}
        </button>
      </template>
    </div>

    <div v-if="info" class="vip-period-toggle">
      <button
        type="button"
        class="vip-period-toggle__btn"
        :class="{ 'is-active': period === 'month' }"
        @click="period = 'month'"
      >Monthly</button>
      <button
        type="button"
        class="vip-period-toggle__btn"
        :class="{ 'is-active': period === 'year' }"
        @click="period = 'year'"
      >Yearly <span class="vip-period-toggle__save">Save 25%</span></button>
    </div>

    <div v-if="info" class="vip-tiers-grid">
      <div
        v-for="(tier, key) in info.tiers"
        :key="key"
        class="vip-tier-card"
        :class="{ 'is-featured': key === 'gold' }"
      >
        <div v-if="key === 'gold'" class="vip-tier-card__badge">MOST POPULAR</div>
        <div class="ox vip-tier-card__label">{{ tier.label }}</div>
        <div class="vip-tier-card__price">
          <span class="ox vip-tier-card__price-amount">{{ formatCents(period === 'year' ? tier.yearly_price_cents : tier.price_cents) }}</span>
          <span class="vip-tier-card__price-period">{{ period === 'year' ? '/yr' : '/mo' }}</span>
        </div>
        <div class="vip-tier-card__headline-perks">
          <div v-for="(perkFn, i) in HEADLINE_PERKS" :key="i" class="vip-tier-card__perk vip-tier-card__perk--headline">
            <span class="vip-tier-card__perk-check">✔</span>{{ perkFn(tier) }}
          </div>
        </div>

        <button type="button" class="vip-tier-card__expand-btn" @click="toggleExpanded(key)">
          {{ expandedTiers.has(key) ? 'Hide full details ▲' : `+${extraPerkCount(key)} more benefits ▾` }}
        </button>

        <div v-if="expandedTiers.has(key)" class="vip-tier-card__perks">
          <div v-for="section in PERK_SECTIONS" :key="section.title" class="vip-perk-section">
            <div class="vip-perk-section__title">{{ section.title }}</div>
            <div v-for="(perkFn, i) in section.perks" :key="i" class="vip-tier-card__perk">
              <span class="vip-tier-card__perk-check">✔</span>{{ perkFn(tier) }}
            </div>
          </div>
          <div class="vip-perk-section">
            <div class="vip-perk-section__title">Quality of Life</div>
            <div v-for="perk in COSMETIC_PERKS[key] || []" :key="perk" class="vip-tier-card__perk">
              <span class="vip-tier-card__perk-check">✔</span>{{ perk }}
            </div>
          </div>
        </div>
        <button
          @click="subscribe(key)"
          class="vip-subscribe-btn"
          :class="{ 'vip-subscribe-btn--current': tierAction(key) === 'current' }"
          :disabled="tierAction(key) === 'current'"
        >
          {{ tierButtonLabel(key) }}
        </button>
      </div>
    </div>

    <div v-if="info?.lifetime" class="vip-lifetime-card">
      <div class="vip-lifetime-card__badge">ONE-TIME · NO RENEWALS</div>
      <div class="ox vip-lifetime-card__label">{{ info.lifetime.label }}</div>
      <p class="vip-lifetime-card__desc">
        Pay once, keep {{ info.tiers[info.lifetime.grants_tier]?.label }} perks forever, no recurring billing, ever.
      </p>
      <div class="vip-lifetime-card__price">
        <span class="ox vip-lifetime-card__price-amount">{{ formatCents(info.lifetime.price_cents) }}</span>
        <span class="vip-lifetime-card__price-period">once</span>
      </div>
      <button
        @click="purchaseLifetime"
        class="vip-subscribe-btn"
        :class="{ 'vip-subscribe-btn--current': info.vip_lifetime }"
        :disabled="info.vip_lifetime || purchasingLifetime"
      >
        {{ info.vip_lifetime ? 'You own Lifetime Rank' : (purchasingLifetime ? 'Redirecting…' : 'Buy Lifetime Rank') }}
      </button>
    </div>

    <div class="vip-footer">
      Ad-free is included in every Rank tier.
      <router-link to="/gem-store" class="vip-footer__link">Or remove ads only, a one-time gem-store purchase</router-link>
    </div>
  </div>
</template>

<style lang="scss" src="./VipPage.scss" scoped></style>
