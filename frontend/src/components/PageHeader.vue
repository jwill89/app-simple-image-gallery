<script setup lang="ts">
import AppBreadcrumb from './AppBreadcrumb.vue'

/**
 * The single page header used by every view.
 *
 * Page titles used to be whichever of `.title` (32px) or `.title.is-4` (24px)
 * a view happened to reach for, so the heading changed size between sections
 * for no reason a reader could perceive.
 *
 * It also absorbs the breadcrumb. The trail is worth keeping on nested pages,
 * but it doesn't justify its own 48px band above every page — so the last
 * crumb *is* the title, and the separate `<h1>` goes away.
 */
withDefaults(
  defineProps<{
    title: string
    /** Right-aligned metadata, e.g. "5,282 items · page 1 of 133". */
    meta?: string
    /** Show the breadcrumb trail above the title. */
    breadcrumb?: boolean
  }>(),
  { meta: '', breadcrumb: true },
)
</script>

<template>
  <header class="page-header">
    <div class="page-header-main">
      <AppBreadcrumb v-if="breadcrumb" />
      <h1 class="page-title">{{ title }}</h1>
    </div>
    <p v-if="meta" class="page-meta">{{ meta }}</p>
    <div v-if="$slots.actions" class="page-actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-end;
  gap: var(--sp-3);
  flex-wrap: wrap;
  margin-bottom: var(--sp-4);
}

.page-header-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.page-title {
  font-size: var(--t-xl);
  font-weight: 650;
  letter-spacing: -0.015em;
  line-height: var(--lh-tight);
  color: var(--text-1);
  margin: 0;
}

.page-meta {
  font-family: var(--font-mono);
  font-size: var(--t-sm);
  font-variant-numeric: tabular-nums;
  color: var(--text-3);
  margin: 0 0 0 auto;
  white-space: nowrap;
}

.page-actions {
  display: flex;
  gap: var(--sp-2);
  flex-wrap: wrap;
  margin-left: auto;
}

/* When both are present the meta shouldn't also claim the auto margin. */
.page-meta + .page-actions {
  margin-left: var(--sp-3);
}

@media screen and (width <= 640px) {
  .page-header {
    align-items: flex-start;
  }

  .page-meta,
  .page-actions {
    margin-left: 0;
  }
}
</style>
