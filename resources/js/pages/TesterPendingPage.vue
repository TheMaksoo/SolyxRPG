<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../api/client';

const router = useRouter();
const auth = useAuthStore();

const rejected = ref(auth.testerRejected);
const rejectionReason = ref(auth.testerRejectionReason);

// Poll every 30 seconds so the page auto-advances the moment a GM approves the account —
// no need for the tester to manually refresh.
let pollInterval = null;

async function checkStatus() {
  try {
    const { data } = await api.get('/tester/status');
    if (data.approved) {
      // Reload the full auth state so the store reflects the new role.
      await auth.fetchMe();
      const hasAnyCharacters = (auth.user?.characters?.length ?? 0) > 0;
      router.push(hasAnyCharacters ? '/characters' : '/character/create');
    } else if (data.rejected) {
      rejected.value = true;
      rejectionReason.value = data.rejection_reason;
    }
  } catch {
    // Ignore network blips — keep polling.
  }
}

async function logout() {
  await auth.logout();
  router.push('/landing');
}

onMounted(() => {
  // Immediately check in case approval happened between the register call and page load.
  checkStatus();
  pollInterval = setInterval(checkStatus, 30_000);
});

onUnmounted(() => {
  clearInterval(pollInterval);
});
</script>

<template>
  <div class="tester-pending">
    <div class="tester-pending__card">
      <img src="/images/solyx-logo.png" alt="Solyx" class="tester-pending__logo" width="80" height="73" />

      <!-- Rejected state -->
      <template v-if="rejected">
        <div class="tester-pending__icon tester-pending__icon--rejected">✗</div>
        <h1 class="ox tester-pending__title">Application Not Accepted</h1>
        <p class="tester-pending__message">
          Unfortunately your tester application was not approved at this time.
        </p>
        <div v-if="rejectionReason" class="tester-pending__reason">
          <strong>Reason:</strong> {{ rejectionReason }}
        </div>
        <p class="tester-pending__hint">
          If you believe this is a mistake, please reach out to the GM on Discord.
        </p>
      </template>

      <!-- Pending state -->
      <template v-else>
        <div class="tester-pending__icon">⏳</div>
        <h1 class="ox tester-pending__title">Application Submitted!</h1>
        <p class="tester-pending__message">
          Your account has been created and your tester application is
          <strong>awaiting GM approval</strong>.
        </p>
        <p class="tester-pending__hint">
          A GM will review your application soon. This page will automatically update
          the moment you're approved — no need to refresh.
        </p>
        <div class="tester-pending__pulse">
          <span class="tester-pending__pulse-dot"></span>
          Waiting for approval…
        </div>
      </template>

      <button class="tester-pending__logout" @click="logout">
        Log out
      </button>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.tester-pending {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: radial-gradient(ellipse at top, #1a1040 0%, #0a0a0f 70%);
}

.tester-pending__card {
  max-width: 520px;
  width: 100%;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 48px 40px;
  text-align: center;
}

.tester-pending__logo {
  margin-bottom: 24px;
  opacity: 0.9;
}

.tester-pending__icon {
  font-size: 56px;
  margin-bottom: 16px;
  animation: icon-float 3s ease-in-out infinite;

  &--rejected {
    color: #ef4444;
    animation: none;
    font-style: normal;
    font-size: 48px;
    font-weight: 700;
    line-height: 1;
  }
}

@keyframes icon-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-6px); }
}

.tester-pending__title {
  font-size: 28px;
  color: #fff;
  margin-bottom: 16px;
}

.tester-pending__message {
  font-size: 16px;
  color: rgba(255, 255, 255, 0.8);
  line-height: 1.6;
  margin-bottom: 20px;
}

.tester-pending__reason {
  background: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.3);
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.85);
  margin-bottom: 16px;
  text-align: left;
}

.tester-pending__hint {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.5);
  line-height: 1.6;
  margin-bottom: 28px;
}

.tester-pending__pulse {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.6);
  padding: 10px 20px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 24px;
  margin-bottom: 32px;
}

.tester-pending__pulse-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #a78bfa;
  animation: pulse-dot 1.4s ease-in-out infinite;
}

@keyframes pulse-dot {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.75); }
}

.tester-pending__logout {
  display: block;
  margin: 0 auto;
  padding: 10px 28px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.5);
  background: transparent;
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px;
  cursor: pointer;
  transition: color 0.2s, border-color 0.2s;

  &:hover {
    color: rgba(255, 255, 255, 0.85);
    border-color: rgba(255, 255, 255, 0.35);
  }
}
</style>
