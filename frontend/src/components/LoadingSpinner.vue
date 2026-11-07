<script setup lang="ts">
defineProps<{
  message?: string
}>()
</script>

<template>
  <div class="loading-container">
    <div class="has-text-centered">
      <span class="icon is-large">
        <font-awesome-icon icon="fa-solid fa-spinner" spin-pulse size="3x" />
      </span>
      <p v-if="message" class="mt-3">
        {{ message }}
      </p>
      <p v-else class="mt-3">Loading...</p>
    </div>
  </div>
</template>
