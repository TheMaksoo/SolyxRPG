<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { NAV } from '../navigation';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const requiredLevel = computed(() => Number(route.query.level) || 1);
const featureName = computed(() => route.query.feature || 'this page');
const currentLevel = computed(() => auth.user?.character?.level || 0);

const goBack = () => {
  router.push('/dashboard');
};
</script>

<template>
  <div class="level-required">
    <div class="level-required__icon">🔒</div>
    <h1 class="ox level-required__title">Level Required</h1>
    <p class="level-required__message">
      Hey! You're not high enough level to access {{ featureName }} yet.
    </p>
    <div class="level-required__levels">
      <div class="level-required__current">
        <div class="level-required__label">Your Level</div>
        <div class="ox level-required__value">{{ currentLevel }}</div>
      </div>
      <div class="level-required__arrow">→</div>
      <div class="level-required__needed">
        <div class="level-required__label">Required Level</div>
        <div class="ox level-required__value level-required__value--required">{{ requiredLevel }}</div>
      </div>
    </div>
    <p class="level-required__hint">
      Keep battling, completing quests, and exploring to gain experience and level up!
    </p>
    <button @click="goBack" class="level-required__btn">
      Return to Dashboard
    </button>
  </div>
</template>

<style lang="scss" scoped>
.level-required {
  max-width: 600px;
  margin: 80px auto;
  padding: 40px 20px;
  text-align: center;
}

.level-required__icon {
  font-size: 64px;
  margin-bottom: 20px;
  opacity: 0.8;
}

.level-required__title {
  font-size: 32px;
  margin-bottom: 16px;
  color: #fff;
}

.level-required__message {
  font-size: 18px;
  color: rgba(255, 255, 255, 0.8);
  margin-bottom: 32px;
  line-height: 1.6;
}

.level-required__levels {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 24px;
  margin-bottom: 32px;
  padding: 24px;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.level-required__current,
.level-required__needed {
  flex: 1;
  max-width: 140px;
}

.level-required__label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: rgba(255, 255, 255, 0.6);
  margin-bottom: 8px;
}

.level-required__value {
  font-size: 36px;
  color: #fff;
}

.level-required__value--required {
  color: #fbbf24;
}

.level-required__arrow {
  font-size: 24px;
  color: rgba(255, 255, 255, 0.4);
  margin: 0 8px;
}

.level-required__hint {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.6);
  margin-bottom: 32px;
  line-height: 1.6;
}

.level-required__btn {
  padding: 12px 32px;
  font-size: 16px;
  font-weight: 600;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
  }

  &:active {
    transform: translateY(0);
  }
}
</style>
