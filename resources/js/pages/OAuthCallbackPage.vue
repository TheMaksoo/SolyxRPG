<script setup>
import { onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { ensureCsrfCookie } from '../api/client';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

onMounted(async () => {
  try {
    // Refresh the CSRF cookie before touching the session — after the OAuth redirect chain the
    // XSRF-TOKEN cookie may be stale, and the session may not yet be fully propagated. Calling
    // /sanctum/csrf-cookie here forces a synchronous round-trip that establishes a fresh CSRF
    // token AND confirms the session is ready before we try to read it.
    await ensureCsrfCookie();
    await auth.fetchMe();
  } catch {
    // fetchMe already handles its own errors and sets user to null
  }

  if (!auth.isAuthenticated) {
    router.replace('/landing?oauth_error=failed');
    return;
  }

  const dest = route.query.to || (auth.hasCharacter ? '/dashboard' : '/character/create');
  router.replace(dest);
});
</script>

<template>
  <div class="oauth-callback">
    <div class="oauth-callback__spinner"></div>
    <p class="oauth-callback__text">Signing you in…</p>
  </div>
</template>

<style scoped>
.oauth-callback {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100vh;
  gap: 1rem;
  background: #0d0f1a;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.95rem;
}
.oauth-callback__spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: #7c6af7;
  border-radius: 50%;
  animation: spin 0.75s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
