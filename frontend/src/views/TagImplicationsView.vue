<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useApi, getErrorMessage, getErrorCode, getErrorStatus } from '../composables/useApi'
import { useToastStore } from '../stores/toast'
import { useTagSearch } from '../composables/useTagSearch'
import { endpoints } from '../api/endpoints'
import type { Tag, TagImplication } from '../types'
import {
  getTextClassById,
  getCategoryClassById,
  getCategoryNameById,
} from '../constants/categories'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import EmptyState from '../components/EmptyState.vue'
import AppDialog from '../components/AppDialog.vue'
import AppTooltip from '../components/AppTooltip.vue'
import PageHeader from '../components/PageHeader.vue'
import { useAuthStore } from '../stores/auth'

const props = defineProps<{
  tagId: number
}>()

const api = useApi()
const toastStore = useToastStore()

const auth = useAuthStore()
// Editing is an admin's.
const authenticated = computed(() => auth.isAdmin)
const loading = ref(false)
const loadFailed = ref(false)

// Tag info
const tagInfo = ref<Tag | null>(null)

// All implications (filtered client-side for this tag)
const allImplications = ref<TagImplication[]>([])

// Implications where this tag is the trigger
const impliesOthers = computed(() => allImplications.value.filter((i) => i.tagId === props.tagId))

// Implications where this tag is implied by others
const impliedByOthers = computed(() =>
  allImplications.value.filter((i) => i.impliedTagId === props.tagId),
)

// Add implication modal state
const showModal = ref(false)
const impliedSearch = ref('')
const impliedTagId = ref<number | null>(null)
const implHelp = ref('')
const implHelpClass = ref('')
const implLoading = ref(false)
const removeTarget = ref<{ tagId: number; impliedTagId: number; label: string } | null>(null)

// The server's best matches for what's typed, leaving out this tag itself.
const impliedOptions = useTagSearch(impliedSearch, () => [props.tagId])

async function loadData() {
  loading.value = true
  loadFailed.value = false
  try {
    const [found, implications] = await Promise.all([
      api.get<Tag[]>(endpoints.tags.list({ ids: [props.tagId] })),
      api.get<TagImplication[]>(endpoints.tagImplications.list),
    ])

    allImplications.value = implications ?? []
    tagInfo.value = found?.[0] ?? null
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to load tag data'))
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function openModal() {
  impliedSearch.value = ''
  impliedTagId.value = null
  implHelp.value = ''
  implHelpClass.value = ''
  showModal.value = true
}

function closeModal() {
  showModal.value = false
}

function selectImplied(tag: Tag) {
  impliedTagId.value = tag.tagId
  impliedSearch.value = tag.tagName
}

async function submitImplication() {
  if (!impliedTagId.value) {
    implHelp.value = 'Please select an implied tag.'
    implHelpClass.value = 'is-danger'
    return
  }

  // Check for duplicate
  const exists = impliesOthers.value.some((i) => i.impliedTagId === impliedTagId.value)
  if (exists) {
    implHelp.value = 'This implication already exists.'
    implHelpClass.value = 'is-warning'
    return
  }

  implLoading.value = true
  try {
    await api.post<TagImplication>(endpoints.tagImplications.list, {
      tagId: props.tagId,
      impliedTagId: impliedTagId.value,
    })
    allImplications.value = (await api.get<TagImplication[]>(endpoints.tagImplications.list)) ?? []
    closeModal()
    toastStore.success('Implication added.')
  } catch (e) {
    if (getErrorCode(e) === 'CycleDetected' || getErrorStatus(e) === 422) {
      implHelp.value = getErrorMessage(e, 'Cannot add this implication — it would create a cycle.')
      implHelpClass.value = 'is-danger'
    } else {
      implHelp.value = getErrorMessage(e, 'Error adding implication.')
      implHelpClass.value = 'is-danger'
    }
  } finally {
    implLoading.value = false
  }
}

/** Removing an implication is destructive and silent, so it gets a real confirm. */
function askRemoveImplication(tagId: number, impliedTagId: number, label: string) {
  removeTarget.value = { tagId, impliedTagId, label }
}

async function removeImplication() {
  const target = removeTarget.value
  if (!target) {
    return
  }
  const { tagId, impliedTagId } = target
  removeTarget.value = null

  try {
    await api.del(endpoints.tagImplications.byPair(tagId, impliedTagId))
    allImplications.value = (await api.get<TagImplication[]>(endpoints.tagImplications.list)) ?? []
    toastStore.success('Implication removed.')
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to remove implication'))
  }
}

onMounted(loadData)
</script>

<template>
  <section class="section">
    <div class="container">
      <LoadingSpinner v-if="loading" />

      <EmptyState
        v-else-if="loadFailed || !tagInfo"
        icon="fa-solid fa-link"
        :title="loadFailed ? 'Could not load tag data.' : 'Tag not found.'"
      >
        <button v-if="loadFailed" type="button" class="button" @click="loadData">
          <span class="icon"><font-awesome-icon icon="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </EmptyState>

      <template v-else>
        <PageHeader :title="tagInfo.tagName" :meta="getCategoryNameById(tagInfo.categoryId)">
          <template v-if="authenticated" #actions>
            <button type="button" class="button is-primary" @click="openModal">
              <span class="icon"><font-awesome-icon icon="fa-solid fa-plus" /></span>
              <span>Add Implication</span>
            </button>
          </template>
        </PageHeader>

        <p class="mb-5 has-text-grey">
          When this tag is added to media, all implied tags below are automatically added too.
          Implications are transitive — if this tag implies <em>A</em> and <em>A</em> implies
          <em>B</em>, then adding this tag will also add both <em>A</em> and <em>B</em>.
        </p>

        <!-- This tag implies -->
        <h2 class="title is-5 mt-5">
          <span class="icon"><font-awesome-icon icon="fa-solid fa-arrow-right" /></span>
          <span>This tag implies ({{ impliesOthers.length }})</span>
        </h2>

        <table v-if="impliesOthers.length > 0" class="table is-striped is-hoverable is-fullwidth">
          <thead>
            <tr>
              <th>Implied Tag</th>
              <th v-if="authenticated" class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="impl in impliesOthers" :key="impl.impliedTagId">
              <td>
                <router-link
                  :to="{ name: 'tag-implications', params: { tagId: impl.impliedTagId } }"
                >
                  <span class="tag is-medium tag--neutral">{{ impl.impliedTagName }}</span>
                </router-link>
              </td>
              <td v-if="authenticated">
                <AppTooltip label="Remove implication">
                  <button
                    type="button"
                    class="button is-small is-danger is-outlined"
                    aria-label="Remove implication"
                    @click="
                      askRemoveImplication(impl.tagId, impl.impliedTagId, impl.impliedTagName)
                    "
                  >
                    <span class="icon"><font-awesome-icon icon="fa-solid fa-trash" /></span>
                  </button>
                </AppTooltip>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="has-text-centered has-text-grey py-4">
          This tag does not imply any other tags.
        </p>

        <hr />

        <!-- Implied by others -->
        <h2 class="title is-5">
          <span class="icon"><font-awesome-icon icon="fa-solid fa-arrow-left" /></span>
          <span>Implied by ({{ impliedByOthers.length }})</span>
        </h2>

        <table v-if="impliedByOthers.length > 0" class="table is-striped is-hoverable is-fullwidth">
          <thead>
            <tr>
              <th>Trigger Tag</th>
              <th v-if="authenticated" class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="impl in impliedByOthers" :key="impl.tagId">
              <td>
                <router-link :to="{ name: 'tag-implications', params: { tagId: impl.tagId } }">
                  <span class="tag is-medium tag--neutral">{{ impl.tagName }}</span>
                </router-link>
              </td>
              <td v-if="authenticated">
                <AppTooltip label="Remove implication">
                  <button
                    type="button"
                    class="button is-small is-danger is-outlined"
                    aria-label="Remove implication"
                    @click="askRemoveImplication(impl.tagId, impl.impliedTagId, impl.tagName)"
                  >
                    <span class="icon"><font-awesome-icon icon="fa-solid fa-trash" /></span>
                  </button>
                </AppTooltip>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="has-text-centered has-text-grey py-4">No other tags imply this tag.</p>
      </template>

      <AppDialog
        :open="showModal"
        :title="`Add Implication for ${tagInfo?.tagName ?? ''}`"
        @update:open="!$event && closeModal()"
      >
        <p class="mb-4">
          When <strong>{{ tagInfo?.tagName }}</strong> is added to a media item, the selected tag
          will be automatically added too.
        </p>
        <div class="field">
          <label class="label" for="implied-tag-search">Implied Tag</label>
          <div class="control">
            <input
              id="implied-tag-search"
              v-model="impliedSearch"
              class="input"
              type="text"
              placeholder="Search for a tag..."
            />
          </div>
          <div v-if="impliedOptions.length > 0" class="dropdown-list">
            <button
              v-for="t in impliedOptions"
              :key="t.tagId"
              class="dropdown-item"
              :class="{ 'is-active': impliedTagId === t.tagId }"
              type="button"
              @click="selectImplied(t)"
            >
              <span :class="getTextClassById(t.categoryId)">{{ t.tagName }}</span>
              <span class="tag is-small ml-2" :class="getCategoryClassById(t.categoryId)">
                {{ getCategoryNameById(t.categoryId) }}
              </span>
            </button>
          </div>
        </div>
        <p v-if="implHelp" class="help" :class="implHelpClass">
          {{ implHelp }}
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-primary"
            :class="{ 'is-loading': implLoading }"
            @click="submitImplication"
          >
            Add Implication
          </button>
          <button type="button" class="button is-ghost" @click="closeModal">Cancel</button>
        </template>
      </AppDialog>

      <AppDialog
        :open="removeTarget !== null"
        title="Remove this implication?"
        destructive
        @update:open="!$event && (removeTarget = null)"
      >
        <p>
          <strong>{{ removeTarget?.label }}</strong> will no longer be applied automatically.
          Existing media keep the tags they already have.
        </p>

        <template #footer>
          <button type="button" class="button is-danger" @click="removeImplication">Remove</button>
          <button type="button" class="button is-ghost" @click="removeTarget = null">Cancel</button>
        </template>
      </AppDialog>
    </div>
  </section>
</template>

<style scoped>
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
</style>
