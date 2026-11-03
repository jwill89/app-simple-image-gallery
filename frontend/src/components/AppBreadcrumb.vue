<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useGalleryStore } from '../stores/gallery'

interface Crumb {
  label: string
  icon: string
  title?: string
  to?: { name: string; params?: Record<string, string | number> }
}

const MAX_VISIBLE_TAGS = 3

function formatTagLabel(tagsParam: string): { label: string; title?: string } {
  const tagList = tagsParam.split(',').map((t) => t.trim())
  if (tagList.length <= MAX_VISIBLE_TAGS) {
    return { label: tagList.join(', ') }
  }
  const visible = tagList.slice(0, MAX_VISIBLE_TAGS).join(', ')
  const remaining = tagList.length - MAX_VISIBLE_TAGS
  return {
    label: `${visible} + ${remaining} more`,
    title: tagList.join(', '),
  }
}

const route = useRoute()
const store = useGalleryStore()

// The implications page names its tag: look it up if it hasn't been seen.
watch(
  () => route.params.tagId,
  (tagId) => {
    if (route.name === 'tag-implications') void store.loadTagsById([Number(tagId)])
  },
  { immediate: true },
)

const crumbs = computed<Crumb[]>(() => {
  const name = route.name as string
  if (!name) return []

  const list: Crumb[] = [{ label: 'Gallery', icon: 'fa-solid fa-house' }]

  if (name === 'media') {
    list.push({ label: 'Media', icon: 'fa-solid fa-images' })
  } else if (name === 'media-with-tags') {
    list.push({
      label: 'Media',
      icon: 'fa-solid fa-images',
      to: { name: 'media', params: { page: 1, perPage: String(route.params.perPage || 40) } },
    })
    if (route.params.tags === 'untagged') {
      list.push({ label: 'Untagged', icon: 'fa-solid fa-ban' })
    } else {
      const { label, title } = formatTagLabel(route.params.tags as string)
      list.push({ label, title, icon: 'fa-solid fa-filter' })
    }
  } else if (name === 'media-tags') {
    list.push({
      label: 'Media',
      icon: 'fa-solid fa-images',
      to: { name: 'media', params: { page: 1, perPage: 40 } },
    })
    list.push({ label: `Media #${String(route.params.id)}`, icon: 'fa-solid fa-circle-info' })
  } else if (name === 'media-random') {
    // Reached via Random — reflect that provenance instead of the media grid.
    list.push({ label: 'Random', icon: 'fa-solid fa-shuffle' })
    list.push({ label: `Media #${String(route.params.id)}`, icon: 'fa-solid fa-circle-info' })
  } else if (name === 'tags') {
    list.push({ label: 'Tags', icon: 'fa-solid fa-tags' })
  } else if (name === 'tag-categories') {
    list.push({ label: 'Tags', icon: 'fa-solid fa-tags', to: { name: 'tags' } })
    list.push({ label: 'Categories', icon: 'fa-solid fa-palette' })
  } else if (name === 'danbooru-rules') {
    list.push({ label: 'Tags', icon: 'fa-solid fa-tags', to: { name: 'tags' } })
    list.push({ label: 'Import Rules', icon: 'fa-solid fa-file-import' })
  } else if (name === 'tag-implications') {
    list.push({ label: 'Tags', icon: 'fa-solid fa-tags', to: { name: 'tags' } })
    const tagId = Number(route.params.tagId)
    const tag = store.tagById(tagId)
    list.push({ label: tag?.tag_name ?? `Tag #${tagId}`, icon: 'fa-solid fa-link' })
  } else if (name === 'favorites') {
    list.push({ label: 'Favorites', icon: 'fa-solid fa-heart' })
  } else if (name === 'upload') {
    list.push({ label: 'Upload', icon: 'fa-solid fa-cloud-arrow-up' })
  } else if (name === 'duplicates') {
    list.push({ label: 'Duplicates', icon: 'fa-solid fa-clone' })
  } else if (name === 'login') {
    list.push({ label: 'Login', icon: 'fa-solid fa-right-to-bracket' })
  }

  return list
})
</script>

<template>
  <nav v-if="crumbs.length > 1" class="breadcrumb app-breadcrumb" aria-label="breadcrumbs">
    <ul>
      <li
        v-for="(crumb, idx) in crumbs"
        :key="idx"
        :class="{ 'is-active': idx === crumbs.length - 1 }"
      >
        <RouterLink v-if="crumb.to && idx < crumbs.length - 1" :to="crumb.to" :title="crumb.title">
          <span class="icon is-small"><i :class="crumb.icon" /></span>
          <span>{{ crumb.label }}</span>
        </RouterLink>
        <a v-else aria-current="page" :title="crumb.title">
          <span class="icon is-small"><i :class="crumb.icon" /></span>
          <span>{{ crumb.label }}</span>
        </a>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
/* Sits inside PageHeader now rather than in its own band above the page, so it
   contributes a single 18px line instead of a 48px block of 16px text. */
.app-breadcrumb {
  padding: 0;
  margin: 0;
  font-size: var(--t-sm);
  line-height: 1.4;
}

.app-breadcrumb :deep(a) {
  padding: 0 var(--sp-1);
}

.app-breadcrumb :deep(li:first-child a) {
  padding-left: 0;
}

/* The last crumb is the page title, rendered by PageHeader directly beneath —
   repeating it here would say the same word twice in a row. */

/* Bulma draws the separator as `li + li::before`, so hiding the item takes its
   separator with it — nothing dangles. */
.app-breadcrumb :deep(li:last-child) {
  display: none;
}
</style>
