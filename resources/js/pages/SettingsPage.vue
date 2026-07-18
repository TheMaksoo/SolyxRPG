<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const providers = ['discord', 'google', 'apple'];

function linkedTo(provider) {
  return auth.user?.social_accounts?.some((a) => a.provider === provider);
}

async function logout() {
  await auth.logout();
  router.push('/landing');
}
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
          @click="logout"
          class="settings-action-btn"
        >
          Log out
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="./SettingsPage.scss" scoped></style>
