<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { NAV, NAV_FOOTER } from '../navigation';

const route = useRoute();
const activeLabel = computed(
  () => [...NAV, ...NAV_FOOTER].find((n) => n.path === route.path)?.label ?? ''
);
</script>

<template>
  <div style="display:flex;min-height:100vh">
    <aside
      style="width:230px;flex:none;background:#0e0e10;border-right:1px solid rgba(255,255,255,.06);position:sticky;top:0;height:100vh;overflow-y:auto;padding:18px 12px;display:flex;flex-direction:column"
    >
      <div style="display:flex;align-items:center;gap:10px;padding:0 6px 16px">
        <img src="/images/solyx-icon.png" alt="" style="width:28px;height:28px" />
        <div>
          <div class="ox" style="font-weight:800;letter-spacing:.06em;font-size:15px">SOLYX</div>
          <div style="font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.1em">WEB GAME</div>
        </div>
      </div>
      <nav style="flex:1">
        <router-link
          v-for="n in NAV"
          :key="n.path"
          :to="n.path"
          custom
          v-slot="{ navigate, isActive }"
        >
          <button
            @click="navigate"
            :style="{
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              width: '100%',
              textAlign: 'left',
              background: isActive ? 'rgba(232,72,47,.13)' : 'transparent',
              border: 'none',
              borderLeft: `3px solid ${isActive ? '#e8482f' : 'transparent'}`,
              color: isActive ? '#fff' : 'rgba(255,255,255,.62)',
              padding: '9px 10px',
              borderRadius: '8px',
              fontSize: '13.5px',
              fontWeight: isActive ? '700' : '500',
              cursor: 'pointer',
              marginBottom: '2px',
            }"
          >
            <span style="width:22px;display:inline-block;text-align:center">{{ n.icon }}</span>
            {{ n.label }}
          </button>
        </router-link>
      </nav>
      <div style="padding:14px 8px 0;margin-top:12px;border-top:1px solid rgba(255,255,255,.06)">
        <router-link
          v-for="n in NAV_FOOTER"
          :key="n.path"
          :to="n.path"
          style="display:flex;align-items:center;gap:8px;padding:8px 10px;font-size:12.5px;color:rgba(255,255,255,.5);border-radius:8px"
        >
          <span style="width:18px;display:inline-block;text-align:center">{{ n.icon }}</span>
          {{ n.label }}
        </router-link>
      </div>
    </aside>

    <div style="flex:1;min-width:0;display:flex;flex-direction:column">
      <header
        style="height:56px;flex:none;display:flex;align-items:center;padding:0 26px;border-bottom:1px solid rgba(255,255,255,.06);position:sticky;top:0;background:#0b0b0c;z-index:5"
      >
        <span class="ox" style="font-size:14px;font-weight:700;color:rgba(255,255,255,.5)"
          >Solyx Web Game <span style="color:rgba(255,255,255,.25)">—</span>
          <span style="color:#fff">{{ activeLabel }}</span></span
        >
      </header>
      <main style="flex:1;min-width:0;padding:26px 30px;max-width:1300px">
        <router-view />
      </main>
    </div>
  </div>
</template>
