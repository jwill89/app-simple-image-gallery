<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import { useTagSearch } from '../composables/useTagSearch'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { Tag, TagListItem, TagListPage } from '../types'
import {
  getTextClassById,
  getCategoryClassById,
  getCategoryClassByName,
  getCategoryNameById,
} from '../constants/categories'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import EmptyState from '../components/EmptyState.vue'
import PageHeader from '../components/PageHeader.vue'
import PaginationBar from '../components/PaginationBar.vue'
import AppDialog from '../components/AppDialog.vue'
import AppMenu from '../components/AppMenu.vue'
import AppMenuItem from '../components/AppMenuItem.vue'
import { useAuthStore } from '../stores/auth'

/** Wait this long after a keystroke before asking the server. */
const INPUT_DELAY_MS = 150

const api = useApi()
const store = useGalleryStore()
const toastStore = useToastStore()

const auth = useAuthStore()
// Editing is an admin's.
const authenticated = computed(() => auth.isAdmin)

// One page of the list, searched, sorted and paged by the server: the gallery
// has thousands of tags, too many to fetch whole and sort here.
const pagedTags = ref<TagListItem[]>([])
const totalTags = ref(0)
const totalPages = ref(1)
const loading = ref(false)
const searchQuery = ref('')
// Default to most-used first — more useful on a management view than the
// symbols-first alphabetical order.
const sortKey = ref<'tag_name' | 'category_name' | 'media_count'>('media_count')
const sortAsc = ref(false)
const currentPage = ref(1)
const pageSize = ref(25)

// Modal & Form state
const showFormModal = ref(false)
const formMode = ref<'new' | 'edit'>('new')
const formTagName = ref('')
const formCategoryId = ref<number | string>('')
const formEditTag = ref<TagListItem | null>(null)
const formHelp = ref('')
const formHelpClass = ref('')

// Migrate modal state
const showMigrateModal = ref(false)
const migrateSourceTag = ref<TagListItem | null>(null)
const migrateTargetSearch = ref('')
const migrateTargetId = ref<number | null>(null)
const migrateHelp = ref('')
const migrateHelpClass = ref('')
const migrateLoading = ref(false)

// Delete modal state
const showDeleteModal = ref(false)
const deleteTargetTag = ref<TagListItem | null>(null)
const deleteMigrateFirst = ref(false)
const deleteMigrateTargetSearch = ref('')
const deleteMigrateTargetId = ref<number | null>(null)
const deleteHelp = ref('')
const deleteHelpClass = ref('')
const deleteLoading = ref(false)

const migrateTargetOptions = useTagSearch(migrateTargetSearch, () =>
  migrateSourceTag.value ? [migrateSourceTag.value.tag_id] : [],
)
const deleteMigrateTargetOptions = useTagSearch(deleteMigrateTargetSearch, () =>
  deleteTargetTag.value ? [deleteTargetTag.value.tag_id] : [],
)

function toggleSort(key: typeof sortKey.value) {
  if (sortKey.value === key) {
    sortAsc.value = !sortAsc.value
  } else {
    sortKey.value = key
    sortAsc.value = true
  }
}

function sortIcon(key: string) {
  if (sortKey.value !== key) {
    return 'fa-solid fa-sort'
  }
  return sortAsc.value ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down'
}

function ariaSort(key: string): 'ascending' | 'descending' | 'none' {
  if (sortKey.value !== key) {
    return 'none'
  }
  return sortAsc.value ? 'ascending' : 'descending'
}

const loadFailed = ref(false)
let loadSeq = 0

async function loadTags(showSpinner = true) {
  if (showSpinner) {
    loading.value = true
  }
  loadFailed.value = false
  // Only the latest request's answer counts; an earlier one may arrive later.
  const seq = ++loadSeq
  try {
    const page = await api.get<TagListPage>(
      endpoints.tags.display({
        page: currentPage.value,
        perPage: pageSize.value,
        search: searchQuery.value.trim(),
        sort: sortKey.value,
        direction: sortAsc.value ? 'asc' : 'desc',
      }),
    )
    if (seq !== loadSeq) {
      return
    }
    pagedTags.value = page?.items ?? []
    totalTags.value = page?.total ?? 0
    totalPages.value = page?.total_pages ?? 1
    // Past the end (the last tag on the last page went): show the new last page.
    if (currentPage.value > totalPages.value) {
      currentPage.value = totalPages.value
    }
  } catch (e) {
    if (seq !== loadSeq) {
      return
    }
    toastStore.error(getErrorMessage(e, 'Failed to load tags'))
    loadFailed.value = true
    pagedTags.value = []
  } finally {
    if (seq === loadSeq) {
      loading.value = false
    }
  }
}

watch([currentPage, pageSize, sortKey, sortAsc], () => void loadTags(false))

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    // A new search starts from its first page (the page watcher then loads it).
    if (currentPage.value !== 1) {
      currentPage.value = 1
    } else {
      void loadTags(false)
    }
  }, INPUT_DELAY_MS)
})

function openNewTagModal() {
  resetForm()
  showFormModal.value = true
}

function editTag(tag: TagListItem) {
  formMode.value = 'edit'
  formEditTag.value = tag
  formTagName.value = tag.tag_name
  formCategoryId.value = tag.category_id
  formHelp.value = `Editing: ${tag.tag_name}`
  formHelpClass.value = 'is-warning'
  showFormModal.value = true
}

function resetForm() {
  formMode.value = 'new'
  formEditTag.value = null
  formTagName.value = ''
  formCategoryId.value = ''
  formHelp.value = ''
  formHelpClass.value = ''
}

function closeModal() {
  showFormModal.value = false
  resetForm()
}

/** An edit sends only what changed; a field left out stays as it is. */
function changedFields(tag: TagListItem): { tag_name?: string; category_id?: number } {
  const changes: { tag_name?: string; category_id?: number } = {}
  if (formTagName.value !== tag.tag_name) {
    changes.tag_name = formTagName.value
  }
  if (Number(formCategoryId.value) !== tag.category_id) {
    changes.category_id = Number(formCategoryId.value)
  }
  return changes
}

async function submitForm() {
  // Strip leading hyphens — they conflict with the negative-tag search prefix convention
  formTagName.value = formTagName.value.replace(/^-+/, '')

  if (!formTagName.value.trim()) {
    formHelp.value = 'Tag name cannot be empty.'
    formHelpClass.value = 'is-danger'
    return
  }

  if (!formCategoryId.value) {
    formHelp.value = 'Please select a category.'
    formHelpClass.value = 'is-danger'
    return
  }

  try {
    if (formMode.value === 'edit' && formEditTag.value !== null) {
      const changes = changedFields(formEditTag.value)
      if (Object.keys(changes).length > 0) {
        await api.patch<Tag>(endpoints.tags.byId(formEditTag.value.tag_id), changes)
      }
    } else {
      await api.post<Tag>(endpoints.tags.create, {
        tag_name: formTagName.value,
        category_id: Number(formCategoryId.value),
      })
    }
    closeModal()
    await loadTags(false)
    void store.refreshTags()
  } catch (e) {
    // The server says which field it refused (a taken name, say) and why.
    formHelp.value =
      getFieldError(e, 'tag_name') ||
      getFieldError(e, 'category_id') ||
      getErrorMessage(e, 'Error saving tag.')
    formHelpClass.value = 'is-danger'
  }
}

let tagNameTimer: ReturnType<typeof setTimeout> | undefined
let tagNameSeq = 0

function onTagNameInput() {
  if (formMode.value === 'edit') {
    return
  }
  clearTimeout(tagNameTimer)
  tagNameTimer = setTimeout(() => void checkTagName(), INPUT_DELAY_MS)
}

/** Whether the typed name is free, asked of the server (names compare without case). */
async function checkTagName() {
  const name = formTagName.value.trim()
  const seq = ++tagNameSeq
  if (!name) {
    formHelp.value = ''
    formHelpClass.value = ''
    return
  }
  try {
    const found = (await api.get<Tag[]>(endpoints.tags.byNames([name]))) ?? []
    if (seq !== tagNameSeq || formMode.value === 'edit') {
      return
    }
    if (found.length > 0) {
      formHelp.value = 'Tag already exists.'
      formHelpClass.value = 'is-danger'
    } else {
      formHelp.value = 'Tag is available.'
      formHelpClass.value = 'is-success'
    }
  } catch {
    // Saving says so if the name is taken; no hint is better than a wrong one.
    if (seq === tagNameSeq) {
      formHelp.value = ''
    }
  }
}

onBeforeUnmount(() => {
  clearTimeout(searchTimer)
  clearTimeout(tagNameTimer)
})

function openMigrateModal(tag: TagListItem) {
  migrateSourceTag.value = tag
  migrateTargetSearch.value = ''
  migrateTargetId.value = null
  migrateHelp.value = ''
  migrateHelpClass.value = ''
  showMigrateModal.value = true
}

function closeMigrateModal() {
  showMigrateModal.value = false
  migrateSourceTag.value = null
}

function selectMigrateTarget(tag: Tag) {
  migrateTargetId.value = tag.tag_id
  migrateTargetSearch.value = tag.tag_name
}

async function submitMigrate() {
  if (!migrateTargetId.value || !migrateSourceTag.value) {
    migrateHelp.value = 'Please select a target tag.'
    migrateHelpClass.value = 'is-danger'
    return
  }

  migrateLoading.value = true
  try {
    await api.post(endpoints.tags.migrate(migrateSourceTag.value.tag_id), {
      target_tag_id: migrateTargetId.value,
    })
    closeMigrateModal()
    await loadTags(false)
    void store.refreshTags()
  } catch (e) {
    migrateHelp.value = getErrorMessage(e, 'Error migrating tag.')
    migrateHelpClass.value = 'is-danger'
  } finally {
    migrateLoading.value = false
  }
}

function openDeleteModal(tag: TagListItem) {
  deleteTargetTag.value = tag
  deleteMigrateFirst.value = false
  deleteMigrateTargetSearch.value = ''
  deleteMigrateTargetId.value = null
  deleteHelp.value = ''
  deleteHelpClass.value = ''
  showDeleteModal.value = true
}

function closeDeleteModal() {
  showDeleteModal.value = false
  deleteTargetTag.value = null
}

function selectDeleteMigrateTarget(tag: Tag) {
  deleteMigrateTargetId.value = tag.tag_id
  deleteMigrateTargetSearch.value = tag.tag_name
}

async function submitDelete() {
  if (!deleteTargetTag.value) {
    return
  }

  if (deleteMigrateFirst.value && !deleteMigrateTargetId.value) {
    deleteHelp.value = 'Please select a tag to migrate to, or uncheck the migrate option.'
    deleteHelpClass.value = 'is-danger'
    return
  }

  deleteLoading.value = true
  try {
    const migrateToId = deleteMigrateFirst.value ? deleteMigrateTargetId.value : 0
    await api.del(
      migrateToId
        ? endpoints.tags.deleteMigrateTo(deleteTargetTag.value.tag_id, migrateToId)
        : endpoints.tags.byId(deleteTargetTag.value.tag_id),
    )
    closeDeleteModal()
    await loadTags(false)
    void store.refreshTags()
  } catch (e) {
    deleteHelp.value = getErrorMessage(e, 'Error deleting tag.')
    deleteHelpClass.value = 'is-danger'
  } finally {
    deleteLoading.value = false
  }
}

onMounted(() => {
  void loadTags()
})
</script>

<template>
  <section class="section">
    <div class="container is-wide">
      <LoadingSpinner v-if="loading" />
      <EmptyState v-else-if="loadFailed" icon="fa-solid fa-tags" title="Could not load tags.">
        <button type="button" class="button" @click="loadTags()">
          <span class="icon"><font-awesome-icon icon="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </EmptyState>
      <template v-else>
        <PageHeader title="Tags" :meta="`${totalTags.toLocaleString()} tags`">
          <template v-if="authenticated" #actions>
            <button type="button" class="button is-primary" @click="openNewTagModal">
              <span class="icon"><font-awesome-icon icon="fa-solid fa-plus" /></span>
              <span>New Tag</span>
            </button>
            <router-link class="button" :to="{ name: 'tag-categories' }">
              <span class="icon"><font-awesome-icon icon="fa-solid fa-palette" /></span>
              <span>Categories</span>
            </router-link>
            <router-link class="button" :to="{ name: 'danbooru-rules' }">
              <span class="icon"><font-awesome-icon icon="fa-solid fa-file-import" /></span>
              <span>Import Rules</span>
            </router-link>
          </template>
        </PageHeader>

        <!-- Search and page size -->
        <div class="field is-grouped mb-4">
          <div class="control is-expanded">
            <input
              v-model="searchQuery"
              class="input"
              type="text"
              placeholder="Search tags..."
              aria-label="Search tags"
            />
          </div>
          <div class="control">
            <div class="select">
              <select v-model.number="pageSize" aria-label="Tags per page">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Tag Table -->
        <div class="table-container">
          <table class="table is-striped is-hoverable is-fullwidth">
            <thead>
              <tr>
                <th :aria-sort="ariaSort('tag_name')">
                  <button type="button" class="sort-header" @click="toggleSort('tag_name')">
                    Tag <font-awesome-icon :icon="sortIcon('tag_name')" />
                  </button>
                </th>
                <th :aria-sort="ariaSort('category_name')">
                  <button type="button" class="sort-header" @click="toggleSort('category_name')">
                    Category <font-awesome-icon :icon="sortIcon('category_name')" />
                  </button>
                </th>
                <th :aria-sort="ariaSort('media_count')">
                  <button type="button" class="sort-header" @click="toggleSort('media_count')">
                    Media <font-awesome-icon :icon="sortIcon('media_count')" />
                  </button>
                </th>
                <th>Implies</th>
                <th v-if="authenticated" class="has-text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tag in pagedTags" :key="tag.tag_id">
                <td>
                  <span class="tag is-medium" :class="getCategoryClassByName(tag.category_name)">
                    {{ tag.tag_name }}
                  </span>
                </td>
                <td>
                  <span class="has-text-weight-semibold">{{ tag.category_name }}</span>
                </td>
                <td>
                  <router-link
                    v-if="tag.media_count > 0"
                    class="has-text-link"
                    :to="{
                      name: 'media-with-tags',
                      params: { page: 1, perPage: 40, tags: tag.tag_name },
                    }"
                  >
                    {{ tag.media_count }}
                  </router-link>
                  <span v-else>0</span>
                </td>
                <td>
                  <router-link
                    v-if="tag.implication_count > 0"
                    :to="{ name: 'tag-implications', params: { tagId: tag.tag_id } }"
                    class="has-text-link"
                  >
                    {{ tag.implication_count }}
                  </router-link>
                  <router-link
                    v-else
                    :to="{ name: 'tag-implications', params: { tagId: tag.tag_id } }"
                    class="has-text-grey-light"
                  >
                    0
                  </router-link>
                </td>
                <!-- One menu instead of three filled buttons per row: at 25
                     rows that was 75 saturated chips, making the least-used
                     control the loudest thing on the page. -->
                <td v-if="authenticated" class="row-actions">
                  <AppMenu :label="`Actions for ${tag.tag_name}`">
                    <AppMenuItem icon="fa-solid fa-pen" @select="editTag(tag)">Edit</AppMenuItem>
                    <AppMenuItem
                      icon="fa-solid fa-arrow-right-arrow-left"
                      @select="openMigrateModal(tag)"
                      >Migrate…</AppMenuItem
                    >
                    <AppMenuItem icon="fa-solid fa-trash" danger @select="openDeleteModal(tag)"
                      >Delete…</AppMenuItem
                    >
                  </AppMenu>
                </td>
              </tr>
              <tr v-if="pagedTags.length === 0">
                <td :colspan="authenticated ? 5 : 4" class="has-text-centered">No tags found.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Below 768px the table becomes a list. As a table it produced 132px
             rows, because the Actions column squeezed to 56px and stacked its
             buttons vertically — 242px off the right edge, where you couldn't
             see the thing setting the row height. -->
        <ul class="tag-list">
          <li v-for="tag in pagedTags" :key="tag.tag_id" class="tag-list-row">
            <div class="tag-list-main">
              <span class="tag" :class="getCategoryClassByName(tag.category_name)">
                {{ tag.tag_name }}
              </span>
              <span class="tag-list-meta">
                {{ tag.category_name }} ·
                <router-link
                  :to="{
                    name: 'media-with-tags',
                    params: { page: 1, perPage: 40, tags: tag.tag_name },
                  }"
                  >{{ tag.media_count.toLocaleString() }} media</router-link
                >
                ·
                <router-link :to="{ name: 'tag-implications', params: { tagId: tag.tag_id } }"
                  >{{ tag.implication_count }} implies</router-link
                >
              </span>
            </div>
            <AppMenu v-if="authenticated" :label="`Actions for ${tag.tag_name}`">
              <AppMenuItem icon="fa-solid fa-pen" @select="editTag(tag)">Edit</AppMenuItem>
              <AppMenuItem icon="fa-solid fa-arrow-right-arrow-left" @select="openMigrateModal(tag)"
                >Migrate…</AppMenuItem
              >
              <AppMenuItem icon="fa-solid fa-trash" danger @select="openDeleteModal(tag)"
                >Delete…</AppMenuItem
              >
            </AppMenu>
          </li>
          <li v-if="pagedTags.length === 0" class="tag-list-empty">No tags found.</li>
        </ul>

        <!-- The same pager as the media grid, rather than a second one that
             could only step one page at a time. -->
        <PaginationBar
          v-if="totalPages > 1"
          class="mt-4"
          :current-page="currentPage"
          :total-pages="totalPages"
          @navigate="currentPage = $event"
        />
      </template>

      <AppDialog
        :open="showFormModal"
        :title="formMode === 'edit' ? 'Edit Tag' : 'New Tag'"
        @update:open="!$event && closeModal()"
      >
        <div class="field">
          <label class="label" for="tag-name">Tag Name</label>
          <div class="control has-icons-left">
            <input
              id="tag-name"
              v-model="formTagName"
              class="input"
              :class="formHelpClass"
              type="text"
              placeholder="Enter tag name"
              @input="onTagNameInput"
              @keyup.enter="submitForm"
            />
            <span class="icon is-left">
              <font-awesome-icon icon="fa-solid fa-tag" />
            </span>
          </div>
          <p v-if="formHelp" class="help" :class="formHelpClass">
            {{ formHelp }}
          </p>
        </div>
        <div class="field">
          <label class="label" for="tag-category">Category</label>
          <div class="control">
            <div class="select is-fullwidth">
              <select id="tag-category" v-model="formCategoryId">
                <option value="">Select a Category</option>
                <option
                  v-for="cat in store.categories"
                  :key="cat.category_id"
                  :value="cat.category_id"
                >
                  {{ cat.category_name }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <template #footer>
          <button type="button" class="button is-primary" @click="submitForm">
            {{ formMode === 'edit' ? 'Save Changes' : 'Create Tag' }}
          </button>
          <button type="button" class="button is-ghost" @click="closeModal">Cancel</button>
        </template>
      </AppDialog>

      <AppDialog
        :open="showMigrateModal"
        title="Migrate Tag"
        @update:open="!$event && closeMigrateModal()"
      >
        <p class="mb-4">
          Migrate all items tagged with
          <strong>{{ migrateSourceTag?.tag_name }}</strong>
          to another tag. Items that already have the target tag will simply have the source tag
          removed.
        </p>
        <div class="field">
          <label class="label" for="migrate-target-search">Target Tag</label>
          <div class="control">
            <input
              id="migrate-target-search"
              v-model="migrateTargetSearch"
              class="input"
              type="text"
              placeholder="Search for target tag..."
            />
          </div>
          <div v-if="migrateTargetOptions.length > 0" class="dropdown-list">
            <button
              v-for="t in migrateTargetOptions"
              :key="t.tag_id"
              class="dropdown-item"
              :class="{ 'is-active': migrateTargetId === t.tag_id }"
              type="button"
              @click="selectMigrateTarget(t)"
            >
              <span :class="getTextClassById(t.category_id)">{{ t.tag_name }}</span>
              <span class="tag is-small ml-2" :class="getCategoryClassById(t.category_id)">{{
                getCategoryNameById(t.category_id)
              }}</span>
            </button>
          </div>
        </div>
        <p v-if="migrateHelp" class="help" :class="migrateHelpClass">
          {{ migrateHelp }}
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-warning"
            :class="{ 'is-loading': migrateLoading }"
            @click="submitMigrate"
          >
            Migrate
          </button>
          <button type="button" class="button is-ghost" @click="closeMigrateModal">Cancel</button>
        </template>
      </AppDialog>

      <AppDialog
        :open="showDeleteModal"
        title="Delete Tag"
        destructive
        @update:open="!$event && closeDeleteModal()"
      >
        <p class="mb-4">
          Are you sure you want to delete
          <strong>{{ deleteTargetTag?.tag_name }}</strong
          >? This will remove the tag from all items.
        </p>
        <div class="field">
          <label class="checkbox">
            <input v-model="deleteMigrateFirst" type="checkbox" />
            Migrate items to another tag before deleting
          </label>
        </div>
        <div v-if="deleteMigrateFirst" class="field">
          <label class="label" for="delete-migrate-target-search">Migrate To</label>
          <div class="control">
            <input
              id="delete-migrate-target-search"
              v-model="deleteMigrateTargetSearch"
              class="input"
              type="text"
              placeholder="Search for target tag..."
            />
          </div>
          <div v-if="deleteMigrateTargetOptions.length > 0" class="dropdown-list">
            <button
              v-for="t in deleteMigrateTargetOptions"
              :key="t.tag_id"
              class="dropdown-item"
              :class="{ 'is-active': deleteMigrateTargetId === t.tag_id }"
              type="button"
              @click="selectDeleteMigrateTarget(t)"
            >
              <span :class="getTextClassById(t.category_id)">{{ t.tag_name }}</span>
              <span class="tag is-small ml-2" :class="getCategoryClassById(t.category_id)">{{
                getCategoryNameById(t.category_id)
              }}</span>
            </button>
          </div>
        </div>
        <p v-if="deleteHelp" class="help" :class="deleteHelpClass">
          {{ deleteHelp }}
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-danger"
            :class="{ 'is-loading': deleteLoading }"
            @click="submitDelete"
          >
            Delete
          </button>
          <button type="button" class="button is-ghost" @click="closeDeleteModal">Cancel</button>
        </template>
      </AppDialog>
    </div>
  </section>
</template>

<style scoped>
/* Sortable column headers as real buttons for keyboard operability. */
.sort-header {
  display: inline-flex;
  align-items: center;
  gap: 0.4em;
  padding: 0;
  margin: 0;
  border: none;
  background: none;
  font: inherit;
  color: inherit;
  cursor: pointer;
}

.sort-header:hover {
  color: var(--text-1);
}

.row-actions {
  text-align: right;
  width: 3rem;
}

/* The menu trigger is quiet until the row is hovered or it takes focus, so a
   page of 25 rows isn't 25 competing controls. */
.row-actions :deep(.menu-trigger) {
  opacity: 0;
}

tbody tr:hover .row-actions :deep(.menu-trigger),
.row-actions :deep(.menu-trigger:focus-visible),
.row-actions :deep(.menu-trigger[data-state='open']) {
  opacity: 1;
}

.dropdown-list {
  border: 1px solid var(--border-strong);
  border-radius: var(--r-md);
  background: var(--surface-1);
  max-height: 200px;
  overflow-y: auto;
  margin-top: var(--sp-1);
}

.dropdown-item {
  border: none;
  font: inherit;
  display: flex;
  align-items: center;
  padding: var(--sp-2) var(--sp-3);
  cursor: pointer;
  font-size: var(--t-md);
  color: var(--text-1);
}

.dropdown-item:hover,
.dropdown-item.is-active {
  background-color: var(--surface-2);
}

/* ── Mobile list ───────────────────────────────────────────
   The table is unusable below ~768px: it overflows by 242px and its rows
   inflate to 132px because of a column you can't even see. */
.tag-list {
  display: none;
  list-style: none;
  margin: 0;
  padding: 0;
}

.tag-list-row {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  padding: var(--sp-2) 0;
  border-bottom: 1px solid var(--border);
}

.tag-list-main {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  min-width: 0;
  flex: 1;
}

.tag-list-meta {
  font-size: var(--t-sm);
  color: var(--text-3);
}

.tag-list-empty {
  padding: var(--sp-4) 0;
  text-align: center;
  color: var(--text-3);
}

@media screen and (width <= 768px) {
  .table-container {
    display: none;
  }

  .tag-list {
    display: block;
  }

  /* No hover on touch, so the trigger is always visible here. */
  .tag-list :deep(.menu-trigger) {
    opacity: 1;
  }
}
</style>
