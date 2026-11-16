<script setup lang="ts">
import {
  DropdownMenuItemIndicator,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
} from 'reka-ui'
import AppMenu from './AppMenu.vue'
import { THEMES, isTheme, useTheme, type Theme } from '../theme'

/**
 * The colour scheme, chosen from the navbar: the device's, light or dark. The menu's
 * items are radio items, so a screen reader hears which one is checked, and the
 * trigger's name says the current one.
 */
const NAMES: Record<Theme, string> = { system: 'Match device', light: 'Light', dark: 'Dark' }

const { theme, setTheme } = useTheme()

function pick(value: unknown) {
  if (isTheme(value)) {
    setTheme(value)
  }
}
</script>

<template>
  <AppMenu :label="`Theme: ${NAMES[theme]}`">
    <template #trigger><font-awesome-icon icon="fa-solid fa-palette" /></template>
    <DropdownMenuLabel class="menu-label">Theme</DropdownMenuLabel>
    <DropdownMenuRadioGroup :model-value="theme" aria-label="Theme" @update:model-value="pick">
      <DropdownMenuRadioItem v-for="name in THEMES" :key="name" :value="name" class="menu-item">
        <span class="menu-item-check" aria-hidden="true">
          <DropdownMenuItemIndicator>
            <font-awesome-icon icon="fa-solid fa-circle-check" />
          </DropdownMenuItemIndicator>
        </span>
        <span>{{ NAMES[name] }}</span>
      </DropdownMenuRadioItem>
    </DropdownMenuRadioGroup>
  </AppMenu>
</template>
