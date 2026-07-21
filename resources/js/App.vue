<script setup>
import CookieConsent from './components/CookieConsent.vue';
</script>

<template>
  <div id="app-root" v-twemoji>
    <router-view />
    <CookieConsent />
  </div>
</template>
