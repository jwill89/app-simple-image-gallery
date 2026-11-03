<script setup lang="ts">
import { onMounted, onUnmounted, ref, useTemplateRef } from 'vue'
import { loadTurnstile } from '../composables/useTurnstile'

/**
 * Cloudflare Turnstile's check that the person filling in a form isn't a bot.
 * The model is its token: '' until the check passes, and again once it
 * expires or reset() is called. A token passes the server once, so a form
 * resets the widget after every try.
 */
const props = defineProps<{
  siteKey: string
  action: 'login' | 'register'
}>()

const token = defineModel<string>({ required: true })
const container = useTemplateRef<HTMLDivElement>('container')
const failed = ref(false)
let widgetId: string | null = null

onMounted(async () => {
  try {
    const turnstile = await loadTurnstile()
    if (!container.value) return
    widgetId = turnstile.render(container.value, {
      sitekey: props.siteKey,
      action: props.action,
      theme: 'auto',
      callback: (value) => {
        token.value = value
      },
      'expired-callback': () => {
        token.value = ''
      },
      'error-callback': () => {
        token.value = ''
      },
    })
  } catch {
    failed.value = true
  }
})

onUnmounted(() => {
  if (widgetId !== null) window.turnstile?.remove(widgetId)
})

function reset(): void {
  token.value = ''
  if (widgetId !== null) window.turnstile?.reset(widgetId)
}

defineExpose({ reset })
</script>

<template>
  <div class="turnstile">
    <div ref="container" />
    <p v-if="failed" class="help is-danger">
      The check that you're not a bot couldn't load. Reload the page to try again.
    </p>
  </div>
</template>

<style scoped>
.turnstile {
  min-height: 65px;
  margin-bottom: var(--sp-3);
}
</style>
