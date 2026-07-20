<script setup>
import { ref } from 'vue';

const STORAGE_KEY = 'solyx_cookie_consent';
const dismissed = ref(localStorage.getItem(STORAGE_KEY) === '1');

function accept() {
  localStorage.setItem(STORAGE_KEY, '1');
  dismissed.value = true;
}
</script>

<template>
  <Teleport to="body">
    <div v-if="!dismissed" class="cookie-consent">
      <p class="cookie-consent__text">
        Solyx uses a session cookie to keep you signed in and local storage for preferences. See our
        <router-link to="/terms#cookies">Cookie &amp; Terms notice</router-link> for details.
      </p>
      <button type="button" class="cookie-consent__btn" @click="accept">Got it</button>
    </div>
  </Teleport>
</template>

<style scoped lang="scss">
.cookie-consent {
  position: fixed;
  bottom: 16px;
  left: 16px;
  right: 16px;
  z-index: 998;
  margin: 0 auto;
  max-width: 560px;
  display: flex;
  align-items: center;
  gap: 14px;
  background: rgba(20, 18, 20, 0.96);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  padding: 14px 16px;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(6px);
}

.cookie-consent__text {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: rgba(255, 255, 255, 0.7);

  a {
    color: #e8482f;

    &:hover {
      text-decoration: underline;
    }
  }
}

.cookie-consent__btn {
  flex: none;
  border: none;
  border-radius: 8px;
  background: #e8482f;
  color: #fff;
  font-weight: 700;
  font-size: 12px;
  padding: 9px 16px;
  cursor: pointer;

  &:hover {
    opacity: 0.9;
  }
}
</style>
