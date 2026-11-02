<script setup lang="ts">
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { getCategoryClassById } from '../constants/categories'

const props = defineProps<{
  tagId: number
  tagName: string
  categoryId: number
  removable?: boolean
}>()

const emit = defineEmits<{
  remove: [tagId: number]
}>()

// Clicking a tag filters the gallery to media carrying that tag. The
// `media-with-tags` route matches on tag name (same param the navbar search
// builds), so we pass the name straight through.
const filterRoute = computed<RouteLocationRaw>(() => ({
  name: 'media-with-tags',
  params: { page: 1, perPage: 40, tags: props.tagName },
}))
</script>

<template>
  <span class="tag is-medium tag-badge" :class="getCategoryClassById(categoryId)">
    <RouterLink class="tag-badge-link" :to="filterRoute" :title="`Show media tagged '${tagName}'`">
      {{ tagName }}
    </RouterLink>
    <button
      v-if="removable"
      type="button"
      class="delete tag-badge-delete"
      aria-label="delete"
      @click.stop.prevent="emit('remove', tagId)"
    />
  </span>
</template>

<style scoped>
.tag-badge {
  /* Let the padding come from the inner link so the whole pill is clickable. */
  padding-right: 0;
}

.tag-badge-link {
  color: inherit;
  padding: 0 0.5em;
  text-decoration: none;
}

.tag-badge-link:hover {
  text-decoration: underline;
}

/* Enlarge the remove target for easier tapping without growing the glyph. */
.tag-badge-delete {
  height: 1.35rem;
  width: 1.35rem;
  min-width: 1.35rem;
  margin-left: 0.1rem;
  margin-right: 0.25rem;
}
</style>
