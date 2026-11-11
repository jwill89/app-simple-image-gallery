<script setup lang="ts">
import { TooltipRoot, TooltipTrigger, TooltipPortal, TooltipContent } from 'reka-ui'

/**
 * Tooltip for icon-only controls.
 *
 * Use this instead of `title`, which never appears on touch, can't be reached
 * by keyboard, and takes a browser-controlled second to show. Reka's opens on
 * focus as well as hover and dismisses on Escape.
 *
 * Requires a `TooltipProvider` ancestor — App.vue mounts one at the root. Without
 * it the context injection throws during setup and takes down the entire
 * surrounding subtree, not just the tooltip.
 */
withDefaults(defineProps<{ label: string; side?: 'top' | 'right' | 'bottom' | 'left' }>(), {
  side: 'bottom',
})
</script>

<template>
  <TooltipRoot :delay-duration="350">
    <TooltipTrigger as-child>
      <slot />
    </TooltipTrigger>
    <TooltipPortal>
      <TooltipContent class="tip" :side="side" :side-offset="6">
        {{ label }}
      </TooltipContent>
    </TooltipPortal>
  </TooltipRoot>
</template>
