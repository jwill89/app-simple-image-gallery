<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useApi, getErrorMessage, hasAuthToken } from '../composables/useApi'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { TagCategory } from '../types'
import { colorToTagClass, VALID_COLORS } from '../constants/categories'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import AppDialog from '../components/AppDialog.vue'
import AppTooltip from '../components/AppTooltip.vue'

const api = useApi()
const store = useGalleryStore()
const toastStore = useToastStore()

const authenticated = ref(hasAuthToken())
const loading = ref(false)
const loadFailed = ref(false)
const categories = ref<TagCategory[]>([])

// Modal state
const showModal = ref(false)
const formMode = ref<'new' | 'edit'>('new')
const formId = ref(0)
const formName = ref('')
const formColor = ref('white')
const formDescription = ref('')
const formSortOrder = ref(0)
const formHelp = ref('')
const formHelpClass = ref('')
const formLoading = ref(false)

// Delete modal
const showDeleteModal = ref(false)
const deleteTarget = ref<TagCategory | null>(null)
const deleteLoading = ref(false)

async function loadCategories() {
  loading.value = true
  loadFailed.value = false
  try {
    categories.value = (await api.get<TagCategory[]>(endpoints.tagCategories.list)) ?? []
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to load categories.'))
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function openNewModal() {
  formMode.value = 'new'
  formId.value = 0
  formName.value = ''
  formColor.value = 'neutral'
  formDescription.value = ''
  formSortOrder.value =
    categories.value.length > 0 ? Math.max(...categories.value.map((c) => c.sort_order)) + 1 : 0
  formHelp.value = ''
  formHelpClass.value = ''
  showModal.value = true
}

function openEditModal(cat: TagCategory) {
  formMode.value = 'edit'
  formId.value = cat.category_id
  formName.value = cat.category_name
  formColor.value = cat.color
  formDescription.value = cat.description
  formSortOrder.value = cat.sort_order
  formHelp.value = ''
  formHelpClass.value = ''
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

async function submitForm() {
  if (!formName.value.trim()) {
    formHelp.value = 'Category name is required.'
    formHelpClass.value = 'is-danger'
    return
  }

  formLoading.value = true
  try {
    const payload = {
      category_name: formName.value.trim(),
      color: formColor.value,
      description: formDescription.value.trim(),
      sort_order: formSortOrder.value,
    }

    if (formMode.value === 'edit') {
      await api.patch<TagCategory>(endpoints.tagCategories.byId(formId.value), payload)
    } else {
      await api.post<TagCategory>(endpoints.tagCategories.list, payload)
    }
    await loadCategories()
    closeModal()
    void store.refreshTags()
    toastStore.success(formMode.value === 'edit' ? 'Category updated.' : 'Category created.')
  } catch (e) {
    formHelp.value = getErrorMessage(e, 'Error saving category.')
    formHelpClass.value = 'is-danger'
  } finally {
    formLoading.value = false
  }
}

function openDeleteConfirm(cat: TagCategory) {
  deleteTarget.value = cat
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleteLoading.value = true
  try {
    await api.del(endpoints.tagCategories.byId(deleteTarget.value.category_id))
    await loadCategories()
    showDeleteModal.value = false
    void store.refreshTags()
    toastStore.success('Category deleted.')
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Could not delete category.'))
    showDeleteModal.value = false
  } finally {
    deleteLoading.value = false
  }
}

onMounted(loadCategories)
</script>

<template>
  <section class="section">
    <div class="container is-wide">
      <LoadingSpinner v-if="loading" />

      <EmptyState
        v-else-if="loadFailed"
        icon="fa-solid fa-palette"
        title="Could not load categories."
      >
        <button type="button" class="button" @click="loadCategories">
          <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </EmptyState>

      <template v-else>
        <PageHeader title="Tag Categories">
          <template v-if="authenticated" #actions>
            <button type="button" class="button is-primary" @click="openNewModal">
              <span class="icon"><i class="fa-solid fa-plus" /></span>
              <span>New Category</span>
            </button>
          </template>
        </PageHeader>

        <p class="mb-5 has-text-grey">
          Manage the tag categories used throughout the gallery. Each category has a display colour,
          which is how tags are colour-coded everywhere they appear.
        </p>

        <!-- Categories Table -->
        <div class="table-container">
          <table class="table is-striped is-hoverable is-fullwidth">
            <thead>
              <tr>
                <th>ID</th>
                <th>Order</th>
                <th>Name</th>
                <th>Description</th>
                <th v-if="authenticated" class="has-text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="cat in categories" :key="cat.category_id">
                <td>{{ cat.category_id }}</td>
                <td>{{ cat.sort_order }}</td>
                <td>
                  <span class="tag is-medium" :class="colorToTagClass(cat.color)">{{
                    cat.category_name
                  }}</span>
                </td>
                <td>{{ cat.description }}</td>
                <td v-if="authenticated" class="has-text-right">
                  <div class="buttons are-small is-right">
                    <AppTooltip label="Edit category">
                      <button
                        type="button"
                        class="button"
                        aria-label="Edit category"
                        @click="openEditModal(cat)"
                      >
                        <span class="icon"><i class="fa-solid fa-pen" /></span>
                      </button>
                    </AppTooltip>
                    <AppTooltip label="Delete category">
                      <button
                        type="button"
                        class="button is-danger"
                        aria-label="Delete category"
                        @click="openDeleteConfirm(cat)"
                      >
                        <span class="icon"><i class="fa-solid fa-trash" /></span>
                      </button>
                    </AppTooltip>
                  </div>
                </td>
              </tr>
              <tr v-if="categories.length === 0">
                <td :colspan="authenticated ? 5 : 4" class="has-text-centered has-text-grey">
                  No categories defined.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <AppDialog
        :open="showModal"
        :title="formMode === 'edit' ? 'Edit Category' : 'New Category'"
        @update:open="!$event && closeModal()"
      >
        <div class="field">
          <label class="label" for="category-name">Category Name</label>
          <div class="control">
            <input
              id="category-name"
              v-model="formName"
              class="input"
              type="text"
              placeholder="e.g. Artist"
              @keyup.enter="submitForm"
            />
          </div>
        </div>
        <div class="field">
          <p id="category-color-label" class="label">Display Color</p>
          <div class="color-picker" role="group" aria-labelledby="category-color-label">
            <button
              v-for="c in VALID_COLORS"
              :key="c"
              type="button"
              class="tag is-medium color-swatch"
              :class="[colorToTagClass(c), { 'is-selected-swatch': formColor === c }]"
              :title="c"
              @click="formColor = c"
            >
              {{ c }}
            </button>
          </div>
          <p class="help mt-2">
            Preview:
            <span class="tag is-medium" :class="colorToTagClass(formColor)">{{
              formName || 'Sample'
            }}</span>
          </p>
        </div>
        <div class="field">
          <label class="label" for="category-description">Description</label>
          <div class="control">
            <textarea
              id="category-description"
              v-model="formDescription"
              class="textarea"
              rows="2"
              placeholder="Brief description shown in the tag help modal"
            />
          </div>
        </div>
        <div class="field">
          <label class="label" for="category-sort-order">Sort Order</label>
          <div class="control">
            <input
              id="category-sort-order"
              v-model.number="formSortOrder"
              class="input"
              type="number"
              min="0"
              @keyup.enter="submitForm"
            />
          </div>
          <p class="help">
            Controls display order in the help modal and tag sorting. Lower numbers appear first.
          </p>
        </div>
        <p v-if="formHelp" class="help" :class="formHelpClass">
          {{ formHelp }}
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-primary"
            :class="{ 'is-loading': formLoading }"
            @click="submitForm"
          >
            {{ formMode === 'edit' ? 'Save Changes' : 'Create Category' }}
          </button>
          <button type="button" class="button is-ghost" @click="closeModal">Cancel</button>
        </template>
      </AppDialog>

      <AppDialog
        :open="showDeleteModal"
        title="Delete Category"
        destructive
        @update:open="showDeleteModal = $event"
      >
        <p>
          Are you sure you want to delete the
          <strong>{{ deleteTarget?.category_name }}</strong> category?
        </p>
        <p class="has-text-danger mt-2">
          <span class="icon"><i class="fa-solid fa-triangle-exclamation" /></span>
          This will only work if no tags are assigned to this category.
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-danger"
            :class="{ 'is-loading': deleteLoading }"
            @click="confirmDelete"
          >
            Delete
          </button>
          <button type="button" class="button is-ghost" @click="showDeleteModal = false">
            Cancel
          </button>
        </template>
      </AppDialog>
    </div>
  </section>
</template>

<style scoped>
.color-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.color-swatch {
  cursor: pointer;
  border: 2px solid transparent;
  transition:
    border-color 0.15s,
    transform 0.15s;
  font-size: 0.7rem !important;
  text-transform: capitalize;
}

.color-swatch:hover {
  transform: scale(1.08);
}

.color-swatch.is-selected-swatch {
  border-color: var(--text-1);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--text-1) 50%, transparent);
  transform: scale(1.08);
}
</style>
