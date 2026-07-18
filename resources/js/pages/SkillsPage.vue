<script setup>
import ScreenPlaceholder from '../components/ScreenPlaceholder.vue';
</script>

<template>
  <ScreenPlaceholder
    icon="✦"
    title="Skills"
    description="Class path, attribute allocation, and the skill tree. Profession choices (spec @20, profession @40, ascension @60) also live here, gated by level."
    :planned="[
      'Attribute point spending: damage / armor / hp / mp / crit',
      'Skill tree nodes gated by skill_points + level_req',
      'Class path picker at the Lv.20 / 40 / 60 tier gates',
      'POST /character/attributes, /character/skills/{skill}, /character/profession',
    ]"
  />
</template>
