<script setup>
const providers = [
  { key: 'discord', label: 'Continue with Discord', color: '#5865f2' },
  { key: 'google', label: 'Continue with Google', color: '#ffffff' },
  { key: 'apple', label: 'Continue with Apple', color: '#ffffff' },
];
</script>

<template>
  <div
    style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;padding:24px;text-align:center"
  >
    <img src="/images/solyx-icon.png" alt="" style="width:64px;height:64px" />
    <div>
      <h1 class="ox" style="font-size:36px;font-weight:800;margin:0 0 8px">SOLYX</h1>
      <p style="color:rgba(255,255,255,.55);font-size:14.5px;margin:0">
        A browser RPG built on top of the Solyx Discord bot.
      </p>
    </div>

    <div style="display:flex;flex-direction:column;gap:10px;width:280px">
      <button
        v-for="p in providers"
        :key="p.key"
        style="padding:11px 16px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#151517;color:#fff;font-size:14px;font-weight:600;cursor:pointer"
      >
        {{ p.label }}
      </button>
    </div>

    <p style="font-size:11.5px;color:rgba(255,255,255,.3);max-width:320px">
      Social login (Socialite) not wired up yet — buttons are placeholders for
      the /auth/{provider}/redirect flow in the build guide.
    </p>
  </div>
</template>
