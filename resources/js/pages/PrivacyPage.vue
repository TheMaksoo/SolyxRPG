<script setup>
</script>

<template>
  <div class="terms-page">
    <div class="terms-page__inner">
      <router-link to="/landing" class="terms-page__back">← Back to Solyx</router-link>
      <h1 class="ox terms-page__title">Privacy Policy</h1>
      <p class="terms-page__updated">Last updated: 2026</p>

      <section class="terms-section">
        <h2 class="terms-section__title">1. What we collect</h2>
        <p>
          To run your account we store your name, email address, password (hashed, never in plain text), and
          whatever gameplay data your character generates — level, inventory, currencies, chat messages, battle
          history, and similar in-game state. If you sign in with Discord or Google, we store the linked account
          identifier and the email it provides. If you make a purchase, Stripe processes your payment details
          directly — we never see or store your card number.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">2. How we use it</h2>
        <p>
          Your data is used to run the game: authenticating you, saving your progress, showing you to other
          players (name, level, character), processing purchases, and responding to support tickets and bug
          reports you submit. We don't sell your data or use it for third-party advertising.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">3. Who can see it</h2>
        <p>
          Other players can see your public character info (name, level, class, cosmetics, guild). Game Masters
          can see your account and character details to provide support, investigate reports, and enforce the
          rules. We don't share your data with outside parties except payment processing (Stripe) and, where
          you've linked them, the OAuth providers (Discord/Google) used to sign you in.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">4. How long we keep it</h2>
        <p>
          We keep your account and character data for as long as your account exists. Short-lived gameplay logs
          (finished battles, dungeon runs, crafting jobs, and similar) are periodically cleaned up after they're
          no longer useful, as described in our data retention practices — this doesn't affect your account,
          character, or currencies.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">5. Deleting your account</h2>
        <p>
          You can permanently delete your account and character at any time from
          <router-link to="/settings">Settings</router-link>. This immediately and irreversibly removes your
          account, character, inventory, and associated gameplay data. If you have an active VIP subscription,
          cancel it separately in Settings first — deleting your account does not automatically stop billing.
        </p>
      </section>

      <section class="terms-section">
        <h2 class="terms-section__title">6. Contact</h2>
        <p>Questions about this policy can be sent through the in-game support ticket system once you're signed in.</p>
      </section>
    </div>
  </div>
</template>

<style lang="scss" src="./TermsPage.scss" scoped></style>
