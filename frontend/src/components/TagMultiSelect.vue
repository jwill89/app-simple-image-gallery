<script setup lang="ts">
import { ref, computed } from 'vue'
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

const searchTerm = ref('')
const open = ref(false)

/** A leading `-` in the input means "add the next pick as an exclusion". */
const negating = computed(() => searchTerm.value.trimStart().startsWith('-'))

const filteredTags = computed(() => {
  let query = searchTerm.value.toLowerCase().trim()
  if (query.startsWith('-')) query = query.substring(1).trim()

  const usedIds = new Set([...props.excludeTagIds, ...props.modelValue.map((id) => Math.abs(id))])
  let results = store.allTags.filter((tag) => !usedIds.has(tag.tag_id))

  if (query.length > 0) {
    results = results
      .filter((tag) => tag.tag_name.toLowerCase().includes(query))
      .sort((a, b) => {
        const aName = a.tag_name.toLowerCase()
        const bName = b.tag_name.toLowerCase()
        const aExact = aName === query
        const bExact = bName === query
        if (aExact !== bExact) return aExact ? -1 : 1
        const aPrefix = aName.startsWith(query)
        const bPrefix = bName.startsWith(query)
        if (aPrefix !== bPrefix) return aPrefix ? -1 : 1
        return aName.localeCompare(bName)
      })
  }

  return results.slice(0, 20)
})

/** Represents a selected tag, either included or excluded. */
interface SelectedTag extends Tag {
  negated: boolean
}

const selectedTagObjects = computed<SelectedTag[]>(() =>
  props.modelValue
    .map((id) => {
      const negated = id < 0
      const tag = store.allTags.find((t) => t.tag_id === Math.abs(id))
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

                <div v-if="filteredTags.length === 20" class="tag-dropdown-footer">
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
