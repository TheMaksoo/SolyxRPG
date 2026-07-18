<script setup>
defineProps({
  icon: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  planned: { type: Array, default: () => [] },
});
</script>

<template>
  <div>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
      <div style="font-size:28px">{{ icon }}</div>
      <h1 class="ox" style="font-size:28px;font-weight:800;margin:0">{{ title }}</h1>
      <span
        style="font-size:11px;color:rgba(255,255,255,.4);background:#151517;border:1px solid rgba(255,255,255,.08);padding:3px 10px;border-radius:20px"
        >Not yet implemented</span
      >
    </div>
    <p style="font-size:14px;color:rgba(255,255,255,.6);max-width:640px;line-height:1.6;margin-bottom:22px">
      {{ description }}
    </p>
    <div
      v-if="planned.length"
      style="background:#151517;border:1px solid rgba(255,255,255,.07);border-radius:13px;padding:20px 22px;max-width:480px"
    >
      <div class="ox" style="font-size:12px;font-weight:700;letter-spacing:.06em;color:rgba(255,255,255,.4);text-transform:uppercase;margin-bottom:12px">
        Planned for this screen
      </div>
      <ul style="margin:0;padding-left:18px">
        <li
          v-for="item in planned"
          :key="item"
          style="font-size:13.5px;color:rgba(255,255,255,.65);margin-bottom:8px;line-height:1.5"
        >
          {{ item }}
        </li>
      </ul>
    </div>
  </div>
</template>
