<script setup lang="ts">
/**
 * The one empty / failed / nothing-here state.
 *
 * Every view hand-rolled this — same icon, same centred column, slightly
 * different spacing each time — and Duplicates used a plain notification bar
 * for the same situation, so the app said "nothing here" two different ways.
 */
withDefaults(
  defineProps<{
    icon: string
    title: string
    hint?: string
  }>(),
  { hint: '' },
)
</script>

<template>
  <div class="empty">
    <span class="empty-icon"><font-awesome-icon :icon="icon" /></span>
    <p class="empty-title">{{ title }}</p>
    <p v-if="hint" class="empty-hint">{{ hint }}</p>
    <div v-if="$slots.default" class="empty-actions">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--sp-2);
  padding: var(--sp-6) var(--sp-4);
  text-align: center;
}

.empty-icon {
  font-size: 2rem;
  color: var(--text-3);
  opacity: 0.7;
  margin-bottom: var(--sp-1);
}

.empty-title {
  font-size: var(--t-lg);
  color: var(--text-2);
  margin: 0;
}

.empty-hint {
  font-size: var(--t-md);
  color: var(--text-3);
  margin: 0;
  max-width: 26rem;
}

.empty-actions {
  display: flex;
  gap: var(--sp-2);
  margin-top: var(--sp-2);
}
</style>
