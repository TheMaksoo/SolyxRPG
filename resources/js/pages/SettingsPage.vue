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
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="font-size:28px">⚙</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">Settings</h1>
    </div>

    <div style="max-width:420px;background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:22px;margin-bottom:16px">
      <h3 class="ox" style="font-size:13px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">Linked Accounts</h3>
      <div v-for="p in providers" :key="p" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="font-size:13.5px;text-transform:capitalize">{{ p }}</span>
        <a
          v-if="!linkedTo(p)"
          :href="`/api/auth/${p}/redirect`"
          style="font-size:12px;color:#ff6a4d"
        >Link</a>
        <span v-else style="font-size:12px;color:#4ade80">Linked</span>
      </div>
    </div>

    <div style="display:flex;gap:10px">
      <router-link
        to="/characters"
        style="padding:11px 24px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-weight:600;text-decoration:none"
      >
        Switch Character
      </router-link>
      <button
        @click="logout"
        style="padding:11px 24px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:transparent;color:#fff;font-weight:600;cursor:pointer"
      >
        Log out
      </button>
    </div>
  </div>
</template>
