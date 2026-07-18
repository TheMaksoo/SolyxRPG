<script setup>
const classes = [
  { key: 'warrior', icon: '⚔', name: 'Warrior', blurb: 'High HP and defense. Tank.' },
  { key: 'mage', icon: '✷', name: 'Mage', blurb: 'Fragile burst caster.' },
  { key: 'rogue', icon: '🗡', name: 'Rogue', blurb: 'Fast, evasive, crit-focused.' },
  { key: 'ranger', icon: '🏹', name: 'Ranger', blurb: 'Precise ranged DPS.' },
];
</script>

<template>
  <div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;gap:26px">
    <h1 class="ox" style="font-size:26px;font-weight:800;margin:0">Choose your class</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;max-width:640px;width:100%">
      <button
        v-for="c in classes"
        :key="c.key"
        style="background:#151517;border:1px solid rgba(255,255,255,.08);border-radius:13px;padding:20px 14px;color:#fff;cursor:pointer;text-align:center"
      >
        <div style="font-size:30px;margin-bottom:8px">{{ c.icon }}</div>
        <div class="ox" style="font-weight:700;font-size:15px;margin-bottom:4px">{{ c.name }}</div>
        <div style="font-size:11.5px;color:rgba(255,255,255,.5)">{{ c.blurb }}</div>
      </button>
    </div>
    <p style="font-size:11.5px;color:rgba(255,255,255,.3);max-width:400px;text-align:center">
      Name + avatar picker and POST /character not wired up yet — this is the
      screen shell only.
    </p>
  </div>
</template>
