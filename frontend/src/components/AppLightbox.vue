<script setup lang="ts">
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle } from 'reka-ui'

/**
 * Full-bleed image viewer.
 *
 * Deliberately not built on AppDialog: this has no header, no padded body and
 * no footer — the image *is* the content, so the usual dialog chrome would just
 * shrink it. It still rides Reka's Dialog for the focus trap, Escape handling
 * and scroll lock.
 */
defineProps<{
  open: boolean
  src: string
  alt: string
  /** e.g. "1333 × 2000" — shown quietly under the image. */
  caption?: string
  /** Direct link to the original file, for opening in a new tab. */
  href?: string
}>()

const emit = defineEmits<{ 'update:open': [value: boolean] }>()
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="lb-overlay" />
      <DialogContent class="lb" :aria-label="alt">
        <DialogTitle class="lb-sr">{{ alt }}</DialogTitle>

        <!-- Clicking the surrounding space closes, the way every image viewer
             behaves; clicking the image itself must not. It's the pointer
             equivalent of Escape, which the Dialog already handles. -->
        <!-- eslint-disable-next-line vuejs-accessibility/click-events-have-key-events, vuejs-accessibility/no-static-element-interactions -->
        <div class="lb-stage" @click="emit('update:open', false)">
          <img :src="src" :alt="alt" class="lb-img" @click.stop />
        </div>

        <div class="lb-bar">
          <span v-if="caption" class="lb-caption">{{ caption }}</span>
          <a v-if="href" :href="href" target="_blank" rel="noopener" class="lb-link">
            Open original
            <font-awesome-icon icon="fa-solid fa-up-right-from-square" size="xs" />
          </a>
          <button type="button" class="lb-close" @click="emit('update:open', false)">
            <font-awesome-icon icon="fa-solid fa-xmark" />
            <span>Close</span>
          </button>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
