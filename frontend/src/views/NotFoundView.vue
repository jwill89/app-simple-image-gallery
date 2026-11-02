<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <section class="section">
    <div class="container has-text-centered">
      <h1 class="title is-1">404</h1>
      <p class="subtitle">Page not found</p>
      <button type="button" class="button is-primary" @click="router.push('/')">
        <span class="icon"><i class="fa-solid fa-home" /></span>
        <span>Go Home</span>
      </button>
    </div>
  </section>
</template>
