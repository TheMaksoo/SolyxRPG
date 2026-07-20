<script setup>
</script>

<template>
  <div class="terms-page">
    <div class="terms-page__inner">
      <router-link to="/landing" class="terms-page__back">← Back to Solyx</router-link>
      <h1 class="ox terms-page__title">Terms of Service &amp; Beta Disclaimer</h1>
      <p class="terms-page__updated">Last updated: 2026</p>

      <section class="terms-section">
        <h2 class="terms-section__title">1. Solyx is in active development</h2>
        <p>
          Solyx is a beta product. Classes, items, quests, balance numbers, prices, and entire systems can be
          added, changed, rebalanced, or removed at any time without prior notice as we keep building the game.
          Features you rely on today may look different — or be gone — tomorrow.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">2. Data and progress are not guaranteed</h2>
        <p>
          While we take reasonable care to protect your account and character data, bugs, migrations, resets, or
          infrastructure issues can occur during a beta. Character progress, inventory, currencies, and other
          stored data can be lost, reset, or altered. Back up anything irreplaceable outside the game where
          possible; we cannot guarantee recovery of lost data.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">3. Purchases are final</h2>
        <p>
          Gems, VIP tiers, cosmetics, and any other paid benefit are sold as-is. If a paid benefit is later
          changed, rebalanced, moved to a different system, or removed as part of ongoing development, or if
          related data is lost for any reason, <strong>no refund will be issued</strong>. Only purchase what
          you're comfortable spending on a game that is still being built.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">4. Conduct</h2>
        <p>
          Don't exploit bugs for unfair advantage, harass other players, or attempt to compromise the game or
          other accounts. Violating this can result in a warning, restriction, or permanent ban at our
          discretion.
        </p>
      </section>

      <section class="terms-section" id="cookies">
        <h2 class="terms-section__title">5. Cookies &amp; local storage</h2>
        <p>
          Solyx uses a session cookie to keep you signed in and small pieces of local storage to remember things
          like your UI preferences and whether you've dismissed this notice. We don't use third-party tracking
          or advertising cookies beyond what's disclosed here — if that changes (for example, if we add ad
          placements), this page will be updated.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">6. Contact</h2>
        <p>Questions about these terms can be sent through the in-game support ticket system once you're signed in.</p>
      </section>
    </div>
  </div>
</template>

<style lang="scss" src="./TermsPage.scss" scoped></style>
