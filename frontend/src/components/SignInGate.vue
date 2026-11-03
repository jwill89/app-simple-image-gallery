<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import EmptyState from './EmptyState.vue'

/**
 * Shows its content to a signed-in account (an admin's, with `admin`), and
 * otherwise says what is needed, with a sign-in link that comes back here.
 */
const props = withDefaults(defineProps<{ admin?: boolean }>(), { admin: false })

const auth = useAuthStore()
const route = useRoute()

const allowed = computed(() => (props.admin ? auth.isAdmin : auth.isSignedIn))
const signInLink = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))
</script>

<template>
  <slot v-if="allowed" />
  <EmptyState
    v-else-if="auth.isSignedIn"
    icon="fa-solid fa-lock"
    title="This page is for admins."
    hint="Your account isn't an admin. An admin can make it one from the Users page."
  />
  <EmptyState
    v-else
    icon="fa-solid fa-lock"
    title="Sign in to see this page."
    :hint="admin ? 'It needs an admin account.' : ''"
  >
    <router-link :to="signInLink" class="button">
      <span class="icon"><i class="fa-solid fa-right-to-bracket" /></span>
      <span>Sign in</span>
    </router-link>
  </EmptyState>
</template>
