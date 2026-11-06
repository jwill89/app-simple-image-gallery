<script setup lang="ts">
import { computed, watch } from 'vue'
import { useGalleryStore } from '../stores/gallery'
import TagMultiSelect from './TagMultiSelect.vue'

const props = defineProps<{
  modelValue: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [tags: string[]]
  search: []
  reset: []
}>()

const store = useGalleryStore()

// The search arrives as names (from the URL): look up any tag not seen yet.
watch(
  () => props.modelValue.join(),
  () => void store.loadTagsByName(props.modelValue.map((name) => name.replace(/^-/, ''))),
  { immediate: true },
)

const selectedIds = computed({
  get: () => {
    return props.modelValue
      .map((name) => {
        const negated = name.startsWith('-')
        const cleanName = negated ? name.substring(1) : name
        const tag = store.tagByName(cleanName)
        if (!tag) {
          return undefined
        }
        return negated ? -tag.tag_id : tag.tag_id
      })
      .filter((id): id is number => id !== undefined)
  },
  set: (ids: number[]) => {
    const names = ids
      .map((id) => {
        const negated = id < 0
        const tag = store.tagById(Math.abs(id))
        if (!tag) {
          return undefined
        }
        return negated ? `-${tag.tag_name}` : tag.tag_name
      })
      .filter((name): name is string => name !== undefined)
    emit('update:modelValue', names)
  },
})

const hasSelectedTags = computed(() => props.modelValue.length > 0)
</script>

<template>
  <div class="tag-search-container">
    <TagMultiSelect v-model="selectedIds" placeholder="Search tags..." @submit="emit('search')">
      <template #actions>
        <div v-if="!hasSelectedTags" class="control">
          <button type="button" class="button" disabled>
            <span class="icon"><i class="fa-solid fa-magnifying-glass" /></span>
          </button>
        </div>
        <template v-else>
          <div class="control">
            <button type="button" class="button is-primary" @click="emit('search')">
              <span class="icon"><i class="fa-solid fa-magnifying-glass" /></span>
              <span>Search</span>
            </button>
          </div>
          <div class="control">
            <button type="button" class="button is-light" @click="emit('reset')">
              <span class="icon"><i class="fa-solid fa-xmark" /></span>
            </button>
          </div>
        </template>
      </template>
    </TagMultiSelect>
  </div>
</template>

<style scoped>
.tag-search-container {
  min-width: 280px;
  max-width: 400px;
}
</style>
