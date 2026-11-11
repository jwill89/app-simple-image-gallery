<script setup lang="ts">
import {
  DropdownMenuRoot,
  DropdownMenuTrigger,
  DropdownMenuPortal,
  DropdownMenuContent,
} from 'reka-ui'

/**
 * Overflow menu.
 *
 * A hand-rolled dropdown that opens on click and closes via a global `window`
 * click listener has no keyboard access at all — no arrow-key navigation, no
 * Escape, no focus return. Reka UI's DropdownMenu handles all of it, including
 * typeahead.
 *
 * Items are `AppMenuItem`s supplied through the default slot.
 */
withDefaults(
  defineProps<{
    label?: string
    align?: 'start' | 'center' | 'end'
  }>(),
  { label: 'More actions', align: 'end' },
)
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger as-child>
      <button type="button" class="menu-trigger" :aria-label="label" :title="label">
        <slot name="trigger">
          <font-awesome-icon icon="fa-solid fa-ellipsis-vertical" />
        </slot>
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent class="menu-surface" :align="align" :side-offset="4">
        <slot />
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>

<style scoped>
.menu-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  padding: 0;
  border: 1px solid transparent;
  border-radius: var(--r-md);
  background: transparent;
  color: var(--text-2);
  cursor: pointer;
  font-size: var(--t-base);
  line-height: 1;
}

.menu-trigger:hover,
.menu-trigger[data-state='open'] {
  background: var(--surface-2);
  border-color: var(--border-strong);
  color: var(--text-1);
}

/* `.menu-surface` lives in style.css — the content renders through a Teleport,
   which scoped styles cannot reach. Only the trigger stays scoped, since it
   renders in place. */
</style>
