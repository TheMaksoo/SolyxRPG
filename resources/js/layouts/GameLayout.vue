<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { NAV, NAV_FOOTER } from '../navigation';
import { useCharacterStore } from '../stores/character';
import api from '../api/client';

const route = useRoute();
const characterStore = useCharacterStore();
const activeLabel = computed(
  () => [...NAV, ...NAV_FOOTER].find((n) => n.path === route.path)?.label ?? ''
);

const unreadCount = ref(0);

async function loadUnread() {
  const { data } = await api.get('/inbox');
  unreadCount.value = data.items.filter((i) => i.invite).length;
}

onMounted(() => {
  if (!characterStore.character) characterStore.fetch();
  loadUnread();
});
</script>

<template>
  <div class="game-layout">
    <aside class="sidebar">
      <div class="sidebar__brand">
        <img src="/images/solyx-icon.png" alt="" class="sidebar__logo" />
        <div>
          <div class="ox sidebar__brand-name">SOLYX</div>
          <div class="sidebar__brand-tag">WEB GAME</div>
        </div>
      </div>
      <nav class="sidebar__nav">
        <router-link
          v-for="n in NAV"
          :key="n.path"
          :to="n.path"
          custom
          v-slot="{ navigate, isActive }"
        >
          <button
            @click="navigate"
            class="sidebar__nav-btn"
            :class="{ 'is-active': isActive }"
          >
            <span class="sidebar__nav-icon">{{ n.icon }}</span>
            {{ n.label }}
          </button>
        </router-link>
      </nav>
      <div class="sidebar__footer">
        <router-link
          v-for="n in NAV_FOOTER"
          :key="n.path"
          :to="n.path"
          class="sidebar__footer-link"
        >
          <span class="sidebar__footer-icon">{{ n.icon }}</span>
          {{ n.label }}
        </router-link>
      </div>
    </aside>

    <div class="layout-main-col">
      <header class="topbar">
        <span class="ox topbar__title"
          >Solyx Web Game <span class="topbar__title-sep">—</span>
          <span class="topbar__title-active">{{ activeLabel }}</span></span
        >
        <div class="topbar__actions">
          <router-link to="/inbox" class="topbar__inbox">
            🔔
            <span v-if="unreadCount > 0" class="topbar__inbox-badge">{{ unreadCount }}</span>
          </router-link>
          <div v-if="characterStore.character" class="topbar__pill topbar__pill--gold">
            <span class="topbar__pill-icon">◉</span> {{ characterStore.character.gold }}
          </div>
          <router-link v-if="characterStore.character" to="/gem-store" class="topbar__pill topbar__pill--gems">
            <span class="topbar__pill-icon">◆</span> {{ characterStore.character.gems }}
          </router-link>
        </div>
      </header>
      <main class="layout-content">
        <router-view />
      </main>
    </div>
  </div>
</template>

<style lang="scss" src="./GameLayout.scss" scoped></style>
