<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount, useTemplateRef } from 'vue'
import {
  ComboboxRoot,
  ComboboxAnchor,
  ComboboxInput,
  ComboboxPortal,
  ComboboxContent,
  ComboboxViewport,
  ComboboxItem,
  ComboboxEmpty,
} from 'reka-ui'
import { useGalleryStore, type Tag } from '../stores/gallery'
import { getCategoryClassById } from '../constants/categories'

/**
 * Tag picker with include/exclude support.
 *
 * `modelValue` holds signed tag IDs: a negative ID means the tag is excluded
 * from the search (rendered struck through, serialised as `-name`).
 *
 * The listbox mechanics come from Reka UI's Combobox. The hand-rolled version
 * closed its dropdown with a 200ms `setTimeout` on blur and exposed no listbox
 * roles or `aria-activedescendant`, so it raced its own click handler and was
 * invisible to screen readers. The negation behaviour is ours — Reka has no
 * concept of a negative selection — so it stays custom.
 */
const props = withDefaults(
  defineProps<{
    modelValue: number[]
    excludeTagIds?: number[]
    placeholder?: string
  }>(),
  {
    excludeTagIds: () => [],
    placeholder: 'Search tags...',
  },
)

const emit = defineEmits<{
  'update:modelValue': [ids: number[]]
  submit: []
}>()

const store = useGalleryStore()

/** Matches shown at once; more come from typing more. */
const RESULT_LIMIT = 20
/** Wait this long after a keystroke before asking the server. */
const SEARCH_DELAY_MS = 150

const searchTerm = ref('')
const open = ref(false)
/** The part of ComboboxRoot's exposed API this uses. */
const combobox = useTemplateRef<{ highlightFirstItem?: () => void }>('combobox')

/** A leading `-` in the input means "add the next pick as an exclusion". */
const negating = computed(() => searchTerm.value.trimStart().startsWith('-'))

/**
 * The server's best matches for what's typed (exact, then prefix, then
 * containing), leaving out tags already picked. The gallery has thousands of
 * tags, so the picker searches rather than filtering a list of them all.
 */
const filteredTags = ref<Tag[]>([])
let searchSeq = 0
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function search() {
  let query = searchTerm.value.trim()
  if (query.startsWith('-')) query = query.substring(1).trim()
  const usedIds = [...props.excludeTagIds, ...props.modelValue.map((id) => Math.abs(id))]

  // Only the latest search's answer counts; an earlier one may arrive later.
  const seq = ++searchSeq
  try {
    const tags = await store.searchTags(query, usedIds, RESULT_LIMIT)
    if (seq !== searchSeq) return
    filteredTags.value = tags
    // Enter picks the best match for what's typed. Reka highlights the first
    // item only when the list goes from empty to not, and these arrive after
    // the keystroke, replacing the last ones, so highlight it here.
    if (query !== '') {
      await nextTick()
      combobox.value?.highlightFirstItem?.()
    }
  } catch {
    if (seq === searchSeq) filteredTags.value = []
  }
}

function searchSoon() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void search(), SEARCH_DELAY_MS)
}

// By content: a parent may hand over an equal array anew (TagSearchInput's
// computed does whenever a lookup adds tags), and that is no reason to search.
watch([searchTerm, () => props.modelValue.join(), () => props.excludeTagIds.join()], searchSoon)
watch(open, (isOpen) => {
  if (isOpen) void search()
})
onBeforeUnmount(() => {
  clearTimeout(searchTimer)
})

/** Represents a selected tag, either included or excluded. */
interface SelectedTag extends Tag {
  negated: boolean
}

// A chip needs its tag's name and category: look up any not seen yet.
watch(
  () => props.modelValue.join(),
  () => void store.loadTagsById(props.modelValue.map((id) => Math.abs(id))),
  { immediate: true },
)

const selectedTagObjects = computed<SelectedTag[]>(() =>
  props.modelValue
    .map((id) => {
      const negated = id < 0
      const tag = store.tagById(Math.abs(id))
      return tag ? { ...tag, negated } : undefined
    })
    .filter((t): t is SelectedTag => t !== undefined),
)

function onSelectTag(tag: Tag) {
  const id = negating.value ? -tag.tag_id : tag.tag_id
  emit('update:modelValue', [...props.modelValue, id])
  searchTerm.value = ''
}

function removeTag(signedId: number) {
  emit(
    'update:modelValue',
    props.modelValue.filter((id) => id !== signedId),
  )
}

function toggleNegate(signedId: number) {
  emit(
    'update:modelValue',
    props.modelValue.map((id) => (id === signedId ? -id : id)),
  )
}

/**
 * Enter submits the search, but only when the listbox isn't offering a choice —
 * otherwise Reka's own Enter handling should win and pick the highlighted tag.
 */
function onEnter() {
  if (open.value && filteredTags.value.length > 0) return
  if (props.modelValue.length > 0) emit('submit')
}

function onBackspace() {
  if (searchTerm.value === '' && props.modelValue.length > 0) {
    emit('update:modelValue', props.modelValue.slice(0, -1))
  }
}
</script>

<template>
  <div class="tag-multiselect">
    <div class="field has-addons">
      <div class="control is-expanded">
        <!-- `model-value` is pinned to null on purpose. Selection lives in this
             component's own signed-ID array (negatives = excluded), so if the
             root were allowed to hold a selection the input would render the
             raw tag_id next to the chip. The filter text is bound on
             ComboboxInput — ComboboxRoot has no `searchTerm` prop in Reka 2.x. -->
        <ComboboxRoot
          ref="combobox"
          v-model:open="open"
          :model-value="null"
          ignore-filter
          :reset-search-term-on-blur="false"
          open-on-focus
          open-on-click
        >
          <ComboboxAnchor class="tag-input-wrapper">
            <!-- Right-click is a shortcut: typing a leading `-` in the input
                 excludes a tag from the keyboard. -->
            <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -->
            <span
              v-for="tag in selectedTagObjects"
              :key="tag.tag_id"
              class="tag"
              :class="
                tag.negated
                  ? ['tag--neutral', 'tag-negated']
                  : getCategoryClassById(tag.category_id)
              "
              :title="
                tag.negated
                  ? `Excluding: ${tag.tag_name} (right-click to include)`
                  : `Including: ${tag.tag_name} (right-click to exclude)`
              "
              @contextmenu.prevent="toggleNegate(tag.negated ? -tag.tag_id : tag.tag_id)"
            >
              <template v-if="tag.negated">-</template>{{ tag.tag_name }}
              <button
                type="button"
                class="delete is-small"
                :aria-label="`Remove ${tag.tag_name}`"
                @click.stop="removeTag(tag.negated ? -tag.tag_id : tag.tag_id)"
              />
            </span>

            <ComboboxInput
              v-model="searchTerm"
              class="tag-multiselect-input"
              :placeholder="placeholder"
              @keydown.enter="onEnter"
              @keydown.backspace="onBackspace"
            />
          </ComboboxAnchor>

          <!-- Portalled: this lives inside `nav.navbar`, which is
               `position: fixed; z-index: 30` and therefore a stacking context —
               an un-portalled dropdown is trapped beneath the page no matter
               what z-index it carries. -->
          <ComboboxPortal>
            <ComboboxContent class="tag-dropdown" position="popper" align="start" :side-offset="4">
              <ComboboxViewport>
                <ComboboxItem
                  v-for="tag in filteredTags"
                  :key="tag.tag_id"
                  class="tag-dropdown-item"
                  :value="tag.tag_id"
                  @select="onSelectTag(tag)"
                >
                  <span class="tag is-small" :class="getCategoryClassById(tag.category_id)">
                    {{ tag.tag_name }}
                  </span>
                </ComboboxItem>

                <ComboboxEmpty class="tag-dropdown-footer">No matching tags</ComboboxEmpty>

                <div v-if="filteredTags.length === RESULT_LIMIT" class="tag-dropdown-footer">
                  Type to filter more results…
                </div>
              </ComboboxViewport>
            </ComboboxContent>
          </ComboboxPortal>
        </ComboboxRoot>
      </div>
      <slot name="actions" :selected-count="modelValue.length" />
    </div>
  </div>
</template>

<style scoped>
.tag-multiselect {
  position: relative;
}

.tag-input-wrapper {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--sp-1);
  padding: 3px var(--sp-2);
  border: 1px solid var(--border-strong);
  border-radius: var(--r-md);
  background: var(--surface-2);
  min-height: 2.125rem;
  max-height: 120px;
  overflow-y: auto;
  cursor: text;
  width: 100%;
}

.tag-input-wrapper:focus-within {
  border-color: var(--focus);
  box-shadow: var(--focus-ring);
}

.tag-multiselect-input {
  flex: 1;
  min-width: 7rem;
  border: none;
  outline: none;

  /* Inherit the app's family. This used to re-declare the old Bulma stack, so
     text typed here rendered in a different typeface from every other input. */
  font-family: inherit;
  font-size: var(--t-md);
  padding: 2px;
  background: transparent;
  color: var(--text-1);
}

.tag-multiselect-input::placeholder {
  color: var(--text-3);
}

/* `.tag-dropdown*` lives in style.css — it renders through a Teleport, which
   scoped styles cannot reach. */

.tag-negated {
  text-decoration: line-through;
}
</style>
