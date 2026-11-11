<script setup lang="ts">
/**
 * A row of label / value statistics.
 *
 * A small uppercase label above a prominent figure, for wherever counts are
 * reported.
 */
defineProps<{
  stats: { label: string; value: string | number }[]
}>()
</script>

<template>
  <dl class="statrow">
    <div v-for="stat in stats" :key="stat.label" class="statrow-item">
      <dt>{{ stat.label }}</dt>
      <dd>{{ stat.value }}</dd>
    </div>
  </dl>
</template>

<style scoped>
.statrow {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 1px;
  margin: 0 0 var(--sp-4);
  background: var(--border);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  overflow: hidden;
}

.statrow-item {
  background: var(--surface-1);
  padding: var(--sp-3);
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.statrow dt {
  font-family: var(--font-mono);
  font-size: var(--t-xs);
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-3);
}

.statrow dd {
  margin: 0;
  font-size: var(--t-base);
  font-weight: 600;
  color: var(--text-1);
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
</style>
