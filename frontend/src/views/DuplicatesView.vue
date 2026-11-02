<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import {
  useApi,
  getErrorMessage,
  getErrorStatus,
  hasAuthToken,
  setAuthToken,
} from '../composables/useApi'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { DuplicateReport, DuplicateMatch, BulkDeleteResult, LoginResponse } from '../types'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import PageHeader from '../components/PageHeader.vue'
import EmptyState from '../components/EmptyState.vue'
import StatRow from '../components/StatRow.vue'
import AppDialog from '../components/AppDialog.vue'

const api = useApi()
const store = useGalleryStore()
const toastStore = useToastStore()

// Auth state
const authenticated = ref(hasAuthToken())
const passwordInput = ref('')
const authError = ref<string | null>(null)
const authLoading = ref(false)

const report = ref<DuplicateReport | null>(null)
const loading = ref(false)
const loadFailed = ref(false)
const scanning = ref(false)
const deleting = ref(false)
const showDeleteConfirm = ref(false)
const dismissing = ref<string | null>(null)
const selectedMedia = ref<Set<number>>(new Set())

const hasSelection = computed(() => selectedMedia.value.size > 0)
const selectionCount = computed(() => selectedMedia.value.size)

async function login() {
  authLoading.value = true
  authError.value = null
  try {
    const result = await api.post<LoginResponse>(endpoints.auth.login, {
      password: passwordInput.value,
    })
    if (!result) throw new Error('No token returned')
    setAuthToken(result.token)
    authenticated.value = true
    passwordInput.value = ''
    await loadReport()
  } catch {
    authError.value = 'Invalid password'
  } finally {
    authLoading.value = false
  }
}

async function loadReport() {
  loading.value = true
  loadFailed.value = false

  try {
    report.value = (await api.get<DuplicateReport>(endpoints.duplicates.report)) ?? null
  } catch (e) {
    const status = getErrorStatus(e)
    if (status === 401) {
      authenticated.value = false
      return
    }
    if (status === 404) {
      report.value = null
    } else {
      toastStore.error(
        getErrorMessage(e, 'Failed to load the duplicates report.'),
        6000,
        'Load Failed',
      )
      loadFailed.value = true
      report.value = null
    }
  } finally {
    loading.value = false
  }
}

async function runScan() {
  scanning.value = true

  try {
    await api.post(endpoints.duplicates.scan, {})
    toastStore.success('The duplicate scan is complete. Loading results...', 4000, 'Scan Complete')
    selectedMedia.value.clear()
    await loadReport()
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'The duplicate scan failed.'), 6000, 'Scan Failed')
  } finally {
    scanning.value = false
  }
}

async function deleteSelected() {
  if (!hasSelection.value) return
  showDeleteConfirm.value = false
  deleting.value = true

  try {
    const ids = Array.from(selectedMedia.value)
    const result = await api.post<BulkDeleteResult>(endpoints.media.bulkDelete, { media_ids: ids })
    toastStore.success(
      `${result?.total_deleted ?? 0} media item(s) removed from the database.`,
      4000,
      'Media Deleted',
    )
    selectedMedia.value.clear()
    await loadReport()
    await store.refreshTotals()
  } catch (e) {
    toastStore.error(
      getErrorMessage(e, 'Failed to delete the selected media.'),
      6000,
      'Delete Failed',
    )
  } finally {
    deleting.value = false
  }
}

async function dismissPair(match: DuplicateMatch) {
  const pairKey = `${match.media_1.media_id}:${match.media_2.media_id}`
  dismissing.value = pairKey

  try {
    await api.post(endpoints.duplicates.dismissals, {
      media_id_1: match.media_1.media_id,
      media_id_2: match.media_2.media_id,
    })
    toastStore.success('This pair will no longer appear in future reports.', 4000, 'Pair Dismissed')

    // Remove from the local report immediately
    if (report.value) {
      report.value.matches = report.value.matches.filter(
        (m) =>
          !(
            m.media_1.media_id === match.media_1.media_id &&
            m.media_2.media_id === match.media_2.media_id
          ),
      )
      report.value.duplicates_found = report.value.matches.length
    }
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Could not dismiss this pair.'), 6000, 'Dismiss Failed')
  } finally {
    dismissing.value = null
  }
}

function toggleSelect(mediaId: number) {
  const newSet = new Set(selectedMedia.value)
  if (newSet.has(mediaId)) {
    newSet.delete(mediaId)
  } else {
    newSet.add(mediaId)
  }
  selectedMedia.value = newSet
}

function isSelected(mediaId: number): boolean {
  return selectedMedia.value.has(mediaId)
}

function selectAll() {
  if (!report.value) return
  const newSet = new Set<number>()
  // Select the second image from each pair (likely the duplicate)
  for (const match of report.value.matches) {
    newSet.add(match.media_2.media_id)
  }
  selectedMedia.value = newSet
}

function clearSelection() {
  selectedMedia.value = new Set()
}

function fullUrl(fileName: string): string {
  return `/media/full/${fileName}`
}

function ssimLabel(ssim: number | null): string {
  if (ssim === null) return 'N/A'
  const pct = (ssim * 100).toFixed(1)
  return `${pct}%`
}

function ssimClass(ssim: number | null): string {
  if (ssim === null) return 'has-text-grey'
  if (ssim >= 0.95) return 'has-text-danger'
  if (ssim >= 0.85) return 'has-text-warning'
  return 'has-text-info'
}

onMounted(() => {
  if (authenticated.value) {
    void loadReport()
  }
})
</script>

<template>
  <section class="section">
    <div class="container">
      <!-- Login Gate -->
      <template v-if="!authenticated">
        <div class="columns is-centered">
          <div class="column is-4">
            <div class="box">
              <h2 class="title is-4 has-text-centered">
                <span class="icon"><i class="fa-solid fa-lock" /></span>
                Authentication Required
              </h2>
              <p class="has-text-centered mb-4">
                Enter the admin password to access the duplicates page.
              </p>
              <div class="field">
                <div class="control has-icons-left">
                  <input
                    v-model="passwordInput"
                    class="input"
                    :class="{ 'is-danger': authError }"
                    type="password"
                    aria-label="Admin password"
                    placeholder="Password"
                    @keyup.enter="login"
                  />
                  <span class="icon is-left"><i class="fa-solid fa-key" /></span>
                </div>
                <p v-if="authError" class="help is-danger">
                  {{ authError }}
                </p>
              </div>
              <button
                type="button"
                class="button is-primary is-fullwidth"
                :class="{ 'is-loading': authLoading }"
                :disabled="authLoading || !passwordInput"
                @click="login"
              >
                Login
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- Authenticated Content -->
      <template v-else>
        <PageHeader title="Duplicates" :meta="report ? `${report.duplicates_found} pairs` : ''">
          <template #actions>
            <button
              type="button"
              class="button"
              :class="{ 'is-loading': scanning }"
              :disabled="scanning"
              @click="runScan"
            >
              <span class="icon"><i class="fa-solid fa-magnifying-glass" /></span>
              <span>Run New Scan</span>
            </button>
            <button
              v-if="hasSelection"
              type="button"
              class="button is-danger"
              :class="{ 'is-loading': deleting }"
              :disabled="deleting"
              @click="showDeleteConfirm = true"
            >
              <span class="icon"><i class="fa-solid fa-trash" /></span>
              <span>Delete Selected ({{ selectionCount }})</span>
            </button>
          </template>
        </PageHeader>

        <!-- Loading -->
        <LoadingSpinner v-if="loading" message="Loading duplicates report..." />

        <EmptyState
          v-else-if="loadFailed"
          icon="fa-solid fa-triangle-exclamation"
          title="Could not load the duplicates report."
        >
          <button type="button" class="button" @click="loadReport">
            <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
            <span>Retry</span>
          </button>
        </EmptyState>

        <EmptyState
          v-else-if="!report"
          icon="fa-solid fa-magnifying-glass"
          title="No duplicate report yet."
          hint="Run a scan to generate one."
        />

        <!-- Report Content -->
        <template v-else>
          <StatRow
            :stats="[
              { label: 'Report file', value: report.report_file },
              { label: 'Generated', value: report.generated_at || 'Unknown' },
              { label: 'Images compared', value: report.images_compared?.toLocaleString() || '—' },
              { label: 'Duplicates found', value: report.duplicates_found },
            ]"
          />

          <!-- Selection Controls -->
          <div v-if="report.matches.length > 0" class="buttons mb-4">
            <button type="button" class="button is-small is-warning" @click="selectAll">
              <span class="icon"><i class="fa-solid fa-check-double" /></span>
              <span>Select All Duplicates (Right Column)</span>
            </button>
            <button
              v-if="hasSelection"
              type="button"
              class="button is-small"
              @click="clearSelection"
            >
              <span class="icon"><i class="fa-solid fa-xmark" /></span>
              <span>Clear Selection</span>
            </button>
          </div>

          <EmptyState
            v-if="report.matches.length === 0"
            icon="fa-solid fa-circle-check"
            title="No duplicate pairs in the latest report."
            :hint="`Scanned ${report.images_compared?.toLocaleString() || '—'} images.`"
          />

          <!-- Duplicate Pairs -->
          <div v-for="(match, index) in report.matches" :key="index" class="box mb-5">
            <!-- Images side by side -->
            <div class="columns">
              <!-- Image 1 -->
              <div class="column is-6">
                <div
                  class="card"
                  :class="{ 'has-background-danger-light': isSelected(match.media_1.media_id) }"
                >
                  <div
                    class="card-content has-text-centered has-background-grey-darker dupe-image-container"
                  >
                    <a
                      :href="fullUrl(match.media_1.file_name)"
                      target="_blank"
                      :aria-label="`Open media #${match.media_1.media_id} full size`"
                    >
                      <img
                        :src="fullUrl(match.media_1.file_name)"
                        alt=""
                        :class="['dupe-image', { 'thumb-blur': store.blurThumbnails }]"
                        loading="lazy"
                      />
                    </a>
                  </div>
                  <footer class="card-footer">
                    <div class="card-footer-item">
                      <label class="checkbox">
                        <input
                          type="checkbox"
                          :checked="isSelected(match.media_1.media_id)"
                          @change="toggleSelect(match.media_1.media_id)"
                        />
                        &nbsp;Select
                      </label>
                    </div>
                    <div class="card-footer-item">
                      <span class="is-size-7">ID: {{ match.media_1.media_id }}</span>
                    </div>
                    <a
                      class="card-footer-item"
                      :href="fullUrl(match.media_1.file_name)"
                      target="_blank"
                      :aria-label="`Open media #${match.media_1.media_id} full size`"
                    >
                      <span class="icon has-text-info-dark"
                        ><i class="fa-solid fa-up-right-from-square"
                      /></span>
                    </a>
                  </footer>
                </div>
              </div>

              <!-- Image 2 -->
              <div class="column is-6">
                <div
                  class="card"
                  :class="{ 'has-background-danger-light': isSelected(match.media_2.media_id) }"
                >
                  <div
                    class="card-content has-text-centered has-background-grey-darker dupe-image-container"
                  >
                    <a
                      :href="fullUrl(match.media_2.file_name)"
                      target="_blank"
                      :aria-label="`Open media #${match.media_2.media_id} full size`"
                    >
                      <img
                        :src="fullUrl(match.media_2.file_name)"
                        alt=""
                        :class="['dupe-image', { 'thumb-blur': store.blurThumbnails }]"
                        loading="lazy"
                      />
                    </a>
                  </div>
                  <footer class="card-footer">
                    <div class="card-footer-item">
                      <label class="checkbox">
                        <input
                          type="checkbox"
                          :checked="isSelected(match.media_2.media_id)"
                          @change="toggleSelect(match.media_2.media_id)"
                        />
                        &nbsp;Select
                      </label>
                    </div>
                    <div class="card-footer-item">
                      <span class="is-size-7">ID: {{ match.media_2.media_id }}</span>
                    </div>
                    <a
                      class="card-footer-item"
                      :href="fullUrl(match.media_2.file_name)"
                      target="_blank"
                      :aria-label="`Open media #${match.media_2.media_id} full size`"
                    >
                      <span class="icon has-text-info-dark"
                        ><i class="fa-solid fa-up-right-from-square"
                      /></span>
                    </a>
                  </footer>
                </div>
              </div>
            </div>

            <!-- Action bar: stats + dismiss -->
            <div class="level mt-3">
              <div class="level-left">
                <div v-if="match.distance !== null" class="level-item">
                  <span class="tag is-medium is-dark">
                    <span class="icon mr-1"><i class="fa-solid fa-arrows-left-right" /></span>
                    Distance: {{ match.distance }}
                  </span>
                </div>
                <div v-if="match.ssim !== null" class="level-item">
                  <span class="tag is-medium" :class="ssimClass(match.ssim)">
                    SSIM: {{ ssimLabel(match.ssim) }}
                  </span>
                </div>
              </div>
              <div class="level-right">
                <div class="level-item">
                  <button
                    type="button"
                    class="button is-warning is-outlined"
                    :class="{
                      'is-loading':
                        dismissing === `${match.media_1.media_id}:${match.media_2.media_id}`,
                    }"
                    :disabled="dismissing !== null"
                    title="Mark as not a duplicate — hides this pair from future reports"
                    @click="dismissPair(match)"
                  >
                    <span class="icon"><i class="fa-solid fa-eye-slash" /></span>
                    <span>Not a Duplicate</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </template>
      </template>

      <!-- Bulk deletion is the most destructive action in the app; it was the
           last thing still behind a native confirm(), which can't be styled,
           can't be focus-trapped and can't be tested. -->
      <AppDialog
        :open="showDeleteConfirm"
        :title="`Delete ${selectionCount} media item${selectionCount === 1 ? '' : 's'}?`"
        destructive
        @update:open="showDeleteConfirm = $event"
      >
        <p>
          This removes {{ selectionCount }} selected item{{ selectionCount === 1 ? '' : 's' }} from
          the database.
        </p>
        <p class="has-text-danger mt-2">
          <span class="icon"><i class="fa-solid fa-triangle-exclamation" /></span>
          This action cannot be undone.
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-danger"
            :class="{ 'is-loading': deleting }"
            :disabled="deleting"
            @click="deleteSelected"
          >
            <span class="icon"><i class="fa-solid fa-trash" /></span>
            <span>Delete {{ selectionCount }}</span>
          </button>
          <button
            type="button"
            class="button is-ghost"
            :disabled="deleting"
            @click="showDeleteConfirm = false"
          >
            Cancel
          </button>
        </template>
      </AppDialog>
    </div>
  </section>
</template>

<style scoped>
.dupe-image-container {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
  max-height: 500px;
  overflow: hidden;
}

.dupe-image {
  max-width: 100%;
  max-height: 480px;
  object-fit: contain;
  border-radius: 4px;
}
</style>
