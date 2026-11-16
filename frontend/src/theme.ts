/**
 * The colour scheme: the device's, or light or dark as the reader picks. The pick is
 * kept in this browser. The active scheme is `data-theme` on <html>, which style.css
 * turns into `color-scheme` (every colour token is light-dark()) and Bulma reads for
 * its own variables; with no pick there is no attribute, and both follow the device.
 *
 * Importing the module applies the stored pick, so main.ts imports it before the app
 * mounts.
 */
import { readonly, ref } from 'vue'
import { readPreference, writePreference } from './storage'

export const THEMES = ['system', 'light', 'dark'] as const

export type Theme = (typeof THEMES)[number]

const STORAGE_KEY = 'theme'

export function isTheme(value: unknown): value is Theme {
  return THEMES.includes(value as Theme)
}

/** The stored pick, or `system` when there is none or storage is out of reach. */
export function storedTheme(): Theme {
  const value = readPreference(STORAGE_KEY)
  return isTheme(value) ? value : 'system'
}

/** Shows a pick on <html>: `data-theme` for light or dark, none to follow the device. */
export function applyTheme(theme: Theme, root: HTMLElement = document.documentElement): void {
  if (theme === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', theme)
  }
}

const current = ref<Theme>(storedTheme())
applyTheme(current.value)

/** Applies a pick and keeps it; `system` forgets the pick. */
export function setTheme(theme: Theme): void {
  current.value = theme
  applyTheme(theme)
  writePreference(STORAGE_KEY, theme === 'system' ? null : theme)
}

/** The current pick, read-only, and the way to change it. */
export function useTheme() {
  return { theme: readonly(current), setTheme }
}
