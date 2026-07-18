<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="⚔"
    title="Battle"
    description="EnemySelect → FightView → ResultView. The server resolves every turn (CombatService) — the client only ever sends an intent (attack / skill / item), never damage numbers."
    :planned="[
      'Enemy select filtered by current zone + character level',
      'Turn-based fight view: attack, skill (MP cost), item (potion)',
      'Result view: gold/xp/gems rewards, level-up + skill/attribute points',
      'Server-authoritative combat math per the build guide §7',
    ]"
  />
</template>
