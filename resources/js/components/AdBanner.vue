<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({
  variant: { type: String, default: 'inline' }, // 'inline' | 'sidebar'
});

const auth = useAuthStore();
const visible = computed(() => !auth.user?.ads_removed && (auth.user?.vip_tier ?? 'none') === 'none');
const stripe = 'repeating-linear-gradient(45deg,#111,#111 10px,#0e0e10 10px,#0e0e10 20px)';
</script>

<template>
  <div
    v-if="visible && variant === 'inline'"
    style="margin-bottom:16px;border:1px dashed rgba(255,255,255,.14);border-radius:10px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"
    :style="{ background: stripe }"
  >
    <div style="display:flex;align-items:center;gap:12px">
      <span style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);border:1px solid rgba(255,255,255,.15);padding:3px 7px;border-radius:5px">AD</span>
      <span style="font-size:12.5px;color:rgba(255,255,255,.55)">Sponsored · your ad here</span>
    </div>
    <router-link
      to="/vip"
      style="background:none;border:1px solid rgba(232,72,47,.3);color:#ff8163;border-radius:7px;padding:6px 12px;font-size:11px;font-weight:700"
      >Remove ads</router-link
    >
  </div>

  <div
    v-else-if="visible && variant === 'sidebar'"
    style="width:170px;flex:none;border:1px dashed rgba(255,255,255,.14);border-radius:12px;padding:14px 12px;text-align:center"
    :style="{ background: stripe }"
  >
    <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.35);border:1px solid rgba(255,255,255,.15);padding:2px 6px;border-radius:5px;display:inline-block;margin-bottom:10px">AD</div>
    <div style="height:340px;display:grid;place-items:center;font-size:12px;color:rgba(255,255,255,.4);line-height:1.5">Your 160×600<br />ad here</div>
    <router-link
      to="/vip"
      style="display:block;width:100%;margin-top:10px;box-sizing:border-box;background:none;border:1px solid rgba(232,72,47,.3);color:#ff8163;border-radius:7px;padding:7px;font-size:11px;font-weight:700"
      >Remove ads</router-link
    >
  </div>
</template>
