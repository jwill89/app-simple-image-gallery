<script setup lang="ts">
import { ref, onErrorCaptured } from 'vue'
import { useToastStore } from '../stores/toast'

// props are consumed directly in the template (fallbackMessage); no script binding needed.
withDefaults(
  defineProps<{
    fallbackMessage?: string
  }>(),
  {
    fallbackMessage: 'Something went wrong. Please try refreshing the page.',
  },
)

const toastStore = useToastStore()
const hasError = ref(false)

onErrorCaptured((err) => {
  hasError.value = true
  const message = err instanceof Error ? err.message : 'An unexpected error occurred'
  toastStore.error(message)
  return false
})

function retry() {
  hasError.value = false
}
</script>

<template>
  <slot v-if="!hasError" />
  <div v-else class="section">
    <div class="container">
      <div class="has-text-centered py-6">
        <span class="icon is-large has-text-grey-light">
          <font-awesome-icon icon="fa-solid fa-triangle-exclamation" size="3x" />
        </span>
        <p class="is-size-5 has-text-grey mt-4">
          {{ fallbackMessage }}
        </p>
        <button type="button" class="button mt-4" @click="retry">
          <span class="icon"><font-awesome-icon icon="fa-solid fa-rotate-right" /></span>
          <span>Try Again</span>
        </button>
      </div>
    </div>
  </div>
</template>
