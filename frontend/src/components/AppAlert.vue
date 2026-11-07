<script setup lang="ts">
/**
 * An inline, in-context alert.
 *
 * The counterpart to the toast system, not a competitor to it. The rule:
 *
 *   • Toast  — the outcome of an action you have finished, reported globally.
 *              Transient, dismissible, stacks bottom-right.
 *   • Alert  — a condition that belongs *next to the thing it is about* and
 *              should stay put while you act on it: a failure inside the dialog
 *              that caused it, a warning attached to one section of a page.
 *
 * A message that would read identically in either place belongs in the toast,
 * so it is reported once. This replaces four ad-hoc Bulma `.notification`
 * blocks that each picked their own colours off the framework palette.
 *
 * The two are deliberately drawn *differently*, not as a matched set — if a
 * pinned alert and a floating toast look alike, nothing on screen tells you
 * which one will disappear on its own. A toast is an object above the page:
 * neutral surface, drop shadow, a severity stripe down one edge, a close
 * button. An alert is part of the page: flat, tinted with the severity itself,
 * no shadow, no stripe, nothing to dismiss. Same tokens, opposite treatment.
 */
withDefaults(
  defineProps<{
    severity?: 'danger' | 'warning' | 'success' | 'info'
    /** Optional bold lead-in above the message. */
    title?: string
  }>(),
  { severity: 'danger', title: '' },
)

const iconMap: Record<string, string> = {
  danger: 'fa-solid fa-circle-exclamation',
  warning: 'fa-solid fa-triangle-exclamation',
  success: 'fa-solid fa-circle-check',
  info: 'fa-solid fa-circle-info',
}
</script>

<template>
  <div class="alert" :data-severity="severity" role="alert">
    <span class="alert-icon"><font-awesome-icon :icon="iconMap[severity]" /></span>
    <div class="alert-content">
      <p v-if="title" class="alert-title">{{ title }}</p>
      <div class="alert-body"><slot /></div>
    </div>
  </div>
</template>

<style scoped>
.alert {
  display: grid;
  grid-template-columns: auto 1fr;
  column-gap: var(--sp-3);
  align-items: start;
  padding: var(--sp-3);
  border-radius: var(--r-md);
  font-size: var(--t-md);
  color: var(--text-1);

  /* No shadow, no border, no severity stripe — all three belong to the toast.
     An alert sits flush in the page and carries its severity as a wash of the
     colour itself, which is the one treatment a toast never uses. */
  background: var(--surface-2);
}

.alert[data-severity='danger'] {
  background: var(--danger-soft);
}

.alert[data-severity='warning'] {
  background: var(--warning-soft);
}

.alert[data-severity='success'] {
  background: var(--success-soft);
}

.alert[data-severity='info'] {
  background: var(--info-soft);
}

.alert-icon {
  line-height: var(--lh-normal);
}

.alert[data-severity='danger'] .alert-icon {
  color: var(--danger);
}

.alert[data-severity='warning'] .alert-icon {
  color: var(--warning);
}

.alert[data-severity='success'] .alert-icon {
  color: var(--success);
}

.alert[data-severity='info'] .alert-icon {
  color: var(--info);
}

/* Severity is carried by the wash and the icon, never by the words. Tinting
   this 14px/600 lead-in with `--danger` measured 4.11:1 against the washed
   background — under the 4.5 that non-large text has to meet — so the text
   stays on `--text-1` and clears it with room to spare. */
.alert-title {
  font-weight: 600;
  color: var(--text-1);
  margin: 0 0 2px;
}

.alert-body {
  min-width: 0;
}
</style>
