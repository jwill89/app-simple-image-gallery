<script setup lang="ts">
import {
  DialogRoot,
  DialogPortal,
  DialogOverlay,
  DialogContent,
  DialogTitle,
  AlertDialogRoot,
  AlertDialogPortal,
  AlertDialogOverlay,
  AlertDialogContent,
  AlertDialogTitle,
} from 'reka-ui'
import { computed } from 'vue'

/**
 * The one modal in the app.
 *
 * A hand-rolled Bulma `.modal` has no focus trap, no Escape handling, no
 * `aria-modal`, and no body scroll lock — Reka UI's Dialog supplies all four.
 * Keeping the chrome here means a modal fix lands once for every dialog.
 *
 * `destructive` swaps in AlertDialog, which is the right primitive when the
 * action can't be undone: it announces as `alertdialog`, and it won't close on
 * an outside click or Escape, so a stray click can't dismiss the confirmation
 * you meant to read.
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    /** Constrain narrow forms; the default suits a two-or-three field dialog. */
    width?: string
    /** Irreversible action — use AlertDialog semantics. */
    destructive?: boolean
  }>(),
  { width: '30rem', destructive: false },
)

const emit = defineEmits<{ 'update:open': [value: boolean] }>()
const Root = computed(() => (props.destructive ? AlertDialogRoot : DialogRoot))
const Portal = computed(() => (props.destructive ? AlertDialogPortal : DialogPortal))
const Overlay = computed(() => (props.destructive ? AlertDialogOverlay : DialogOverlay))
const Content = computed(() => (props.destructive ? AlertDialogContent : DialogContent))
const Title = computed(() => (props.destructive ? AlertDialogTitle : DialogTitle))
</script>

<template>
  <component :is="Root" :open="open" @update:open="emit('update:open', $event)">
    <component :is="Portal">
      <component :is="Overlay" class="dlg-overlay" />
      <component :is="Content" class="dlg" :style="{ maxWidth: width }">
        <header class="dlg-head">
          <component :is="Title" class="dlg-title">{{ title }}</component>
          <button
            v-if="!destructive"
            type="button"
            class="dlg-close"
            aria-label="Close"
            @click="emit('update:open', false)"
          >
            <font-awesome-icon icon="fa-solid fa-xmark" />
          </button>
        </header>

        <div class="dlg-body">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="dlg-foot">
          <slot name="footer" />
        </footer>
      </component>
    </component>
  </component>
</template>

<style scoped>
.dlg-overlay {
  position: fixed;
  inset: 0;
  background: rgb(0 0 0 / 60%);
  z-index: 60;
}

.dlg {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 61;
  width: calc(100vw - 2rem);
  max-height: calc(100vh - 4rem);
  display: flex;
  flex-direction: column;
  background: var(--surface-1);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-3);
  color: var(--text-2);
}

.dlg:focus-visible {
  outline: none;
}

.dlg-head {
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  padding: var(--sp-3) var(--sp-4);
  border-bottom: 1px solid var(--border);
  flex: none;
}

.dlg-title {
  font-size: var(--t-lg);
  font-weight: 600;
  color: var(--text-1);
  margin: 0;
  line-height: var(--lh-tight);
}

.dlg-close {
  margin-left: auto;
  flex: none;
  width: 1.75rem;
  height: 1.75rem;
  border: none;
  border-radius: var(--r-sm);
  background: transparent;
  color: var(--text-3);
  cursor: pointer;
  font-size: var(--t-base);
  line-height: 1;
}

.dlg-close:hover {
  background: var(--surface-2);
  color: var(--text-1);
}

.dlg-body {
  padding: var(--sp-4);
  overflow-y: auto;
  font-size: var(--t-md);
}

.dlg-foot {
  display: flex;
  gap: var(--sp-2);
  padding: var(--sp-3) var(--sp-4);
  border-top: 1px solid var(--border);
  flex: none;
  flex-wrap: wrap;
}
</style>
