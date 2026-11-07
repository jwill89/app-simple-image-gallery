<script setup lang="ts">
import { DropdownMenuItem } from 'reka-ui'

withDefaults(
  defineProps<{
    icon?: string
    /** Renders the item in the semantic danger colour, for destructive actions. */
    danger?: boolean
  }>(),
  { icon: '', danger: false },
)

const emit = defineEmits<{ select: [] }>()
</script>

<template>
  <DropdownMenuItem class="menu-item" :class="{ 'is-danger': danger }" @select="emit('select')">
    <font-awesome-icon v-if="icon" :icon="icon" class="menu-item-icon" />
    <span><slot /></span>
  </DropdownMenuItem>
</template>

<!-- `.menu-item*` lives in style.css: these render inside a teleported menu,
     so a scoped stylesheet would never match them. -->
