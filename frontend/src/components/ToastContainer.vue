<script setup lang="ts">
import { ToastProvider, ToastRoot, ToastTitle, ToastDescription, ToastViewport } from 'reka-ui'
import { useToastStore, type ToastAction } from '../stores/toast'

/**
 * Toast rendering, on Reka UI.
 *
 * The Pinia store is unchanged — it still owns queueing, timeouts and the undo
 * action. Only the presentation moved, which buys swipe-to-dismiss, per-severity
 * `aria-live` politeness (errors assertive, everything else polite), pause on
 * hover/focus, and a keyboard route into the toast region via F8.
 *
 * The old container also carried fourteen hardcoded colours — Bulma's stock
 * semantic palette plus a grey ramp that matched nothing else in the app — so a
 * success toast was a different green from a success notification. Severity is
 * now a stripe drawn from the same `--success`/`--danger`/`--warning`/`--info`
 * tokens everything else uses. Styles live in style.css because the viewport is
 * portalled, and scoped rules cannot cross a Teleport.
 */
const toastStore = useToastStore()

const iconMap: Record<string, string> = {
  success: 'fa-solid fa-circle-check',
  danger: 'fa-solid fa-circle-exclamation',
  warning: 'fa-solid fa-triangle-exclamation',
  info: 'fa-solid fa-circle-info',
}

/** The store's `danger` is this component's `error`, for the data attribute. */
const severity = (type: string) => (type === 'danger' ? 'error' : type)

/** Run a toast's action (e.g. Undo), then dismiss the toast. */
function runAction(id: number, action: ToastAction) {
  action.handler()
  toastStore.remove(id)
}
</script>

<template>
  <ToastProvider :duration="Infinity" swipe-direction="right">
    <ToastRoot
      v-for="toast in toastStore.toasts"
      :key="toast.id"
      class="toast"
      :data-severity="severity(toast.type)"
      :type="toast.type === 'danger' ? 'foreground' : 'background'"
      :duration="Infinity"
      @update:open="(open: boolean) => !open && toastStore.remove(toast.id)"
    >
      <span class="toast-icon"><i :class="iconMap[toast.type]" /></span>

      <ToastTitle v-if="toast.title" class="toast-title">{{ toast.title }}</ToastTitle>
      <ToastDescription class="toast-body">{{ toast.message }}</ToastDescription>

      <button
        v-if="toast.action"
        type="button"
        class="toast-action"
        @click="runAction(toast.id, toast.action)"
      >
        {{ toast.action.label }}
      </button>

      <button
        type="button"
        class="toast-close"
        aria-label="Dismiss"
        @click="toastStore.remove(toast.id)"
      >
        <i class="fa-solid fa-xmark" />
      </button>
    </ToastRoot>

    <ToastViewport class="toast-viewport" />
  </ToastProvider>
</template>
