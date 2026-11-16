<script setup lang="ts">
import { computed } from 'vue'
import { useGalleryStore, type MediaItem } from '../stores/gallery'
import { useFavoritesStore } from '../stores/favorites'
import { thumbnailBaseName, thumbnailUrl } from '../constants/thumbnails'

const props = defineProps<{
  item: MediaItem
}>()

const emit = defineEmits<{
  click: [id: number]
}>()

const store = useGalleryStore()
const favorites = useFavoritesStore()

const baseName = computed(() => thumbnailBaseName(props.item.fileName))
const thumbnailPath = computed(() => thumbnailUrl(props.item.fileName))
const thumbnail2xPath = computed(() => thumbnailUrl(props.item.fileName, '2x'))

const isAnimated = computed(() => {
  if (props.item.mediaType === 'video') {
    return true
  }
  const ext = props.item.fileName.split('.').pop()?.toLowerCase()
  return ext === 'gif'
})

const isFav = computed(() => favorites.isFavorite(props.item.mediaId))

/** Descriptive text for the thumbnail / its link (filenames are all we have). */
const altText = computed(() => {
  const kind = props.item.mediaType === 'video' ? 'Video' : 'Image'
  return `${kind}: ${baseName.value}`
})

function toggleFavorite(e: Event) {
  e.stopPropagation()
  void favorites.toggle(props.item.mediaId)
}

/** Activate the card (Enter / Space) for keyboard users, mirroring a click. */
function onCardKey(e: KeyboardEvent) {
  e.preventDefault()
  emit('click', props.item.mediaId)
}
</script>

<template>
  <div
    class="gallery-card"
    role="link"
    tabindex="0"
    :aria-label="altText"
    @click="emit('click', item.mediaId)"
    @keydown.enter="onCardKey"
    @keydown.space="onCardKey"
  >
    <div class="gallery-card-inner">
      <div class="gallery-card-thumb">
        <div class="gallery-card-img-wrap">
          <img
            :class="['gallery-card-img', { 'thumb-blur': store.blurThumbnails }]"
            :src="thumbnailPath"
            :srcset="`${thumbnail2xPath} 2x`"
            :alt="altText"
          />
        </div>
        <!-- Animated badge (videos and GIFs) -->
        <span v-if="isAnimated" class="gallery-card-badge">
          <font-awesome-icon
            :icon="item.mediaType === 'video' ? 'fa-solid fa-film' : 'fa-solid fa-play'"
          />
        </span>
        <!-- Favorite heart -->
        <button
          type="button"
          class="gallery-card-heart"
          :class="{ 'is-favorited': isFav }"
          :title="isFav ? 'Remove from favorites' : 'Add to favorites'"
          :aria-label="isFav ? 'Remove from favorites' : 'Add to favorites'"
          :aria-pressed="isFav"
          @click="toggleFavorite"
        >
          <font-awesome-icon :icon="isFav ? 'fa-solid fa-heart' : 'fa-regular fa-heart'" />
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.gallery-card {
  position: relative;
  border-radius: 6px;
  cursor: pointer;

  /* Skip rendering/layout for off-screen cards. Keeps paint & layout cost
     roughly constant as the infinite-scroll list grows. The intrinsic size
     hint matches the 200px card height so the scrollbar stays stable. */
  content-visibility: auto;
  contain-intrinsic-size: 200px 200px;
}

.gallery-card:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 2px;
  border-radius: 6px;
}

.gallery-card-inner {
  height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.gallery-card-thumb {
  position: relative;
  display: inline-block;
  line-height: 0;
}

/* Clips the blurred image so its faded edges are cropped instead of showing a
   hard cut-off at the tile border. */
.gallery-card-img-wrap {
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  border-radius: 6px;
  line-height: 0;
}

.gallery-card-img {
  display: block;
  max-width: 100%;
  max-height: 200px;
  object-fit: contain;
  border-radius: 6px;
  transition:
    filter 0.2s ease,
    transform 0.2s ease;
}

@media (prefers-reduced-motion: reduce) {
  .gallery-card-img {
    transition: none;
  }
}

/* `filter: blur()` fades toward transparent at the image edge; scaling the image
   up inside the clipped wrapper pushes those faded edges out of view so the blur
   fills the tile edge-to-edge. */
.gallery-card-img.thumb-blur {
  transform: scale(1.15);
}

.gallery-card:hover .gallery-card-img:not(.thumb-blur) {
  filter: brightness(1.2);
}

.gallery-card-badge {
  position: absolute;
  top: 4px;
  left: 4px;
  background: rgb(0 0 0 / 70%);
  color: rgb(255 255 255 / 92%);
  font-size: 0.7rem;
  padding: 3px 6px;
  border-radius: 3px;
  pointer-events: none;
}

/* ── Heart button ──────────────────────────────────────── */

.gallery-card-heart {
  position: absolute;
  top: 4px;
  right: 4px;
  background: rgb(0 0 0 / 65%);
  border: none;
  color: rgb(255 255 255 / 85%); /* ≥4.5:1 on the chip, even over a white thumbnail */
  font-size: 0.85rem;
  padding: 4px 6px;
  border-radius: 4px;
  cursor: pointer;
  opacity: 0;
  transition:
    opacity 0.15s,
    color 0.15s,
    transform 0.15s;
  line-height: 1;
}

/* Expand the tap/click target to ≥44px without enlarging the visible chip. */
.gallery-card-heart::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 44px;
  height: 44px;
  transform: translate(-50%, -50%);
}

.gallery-card-heart:focus-visible {
  opacity: 1;
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}

.gallery-card:hover .gallery-card-heart,
.gallery-card-heart.is-favorited {
  opacity: 1;
}

/* The chip is dark over the artwork in either scheme, so the heart takes the
   favourite's pink for a dark ground. */
.gallery-card-heart.is-favorited {
  color: var(--fav-on-scrim);
}

.gallery-card-heart:hover {
  color: var(--fav-on-scrim);
  transform: scale(1.15);
}

.gallery-card-heart:active {
  transform: scale(0.9);
}

/* The heart still shows and changes colour, without fading or growing. */
@media (prefers-reduced-motion: reduce) {
  .gallery-card-heart {
    transition: none;
  }

  .gallery-card-heart:hover,
  .gallery-card-heart:active {
    transform: none;
  }
}
</style>
