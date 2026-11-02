<script setup lang="ts">
import { ref, computed } from 'vue'
import { useApi, getErrorMessage, hasAuthToken, setAuthToken } from '../composables/useApi'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { LoginResponse } from '../types'
import AppAlert from '../components/AppAlert.vue'
import PageHeader from '../components/PageHeader.vue'

const api = useApi()
const store = useGalleryStore()
const toastStore = useToastStore()

// Auth state
const authenticated = ref(hasAuthToken())
const passwordInput = ref('')
const authError = ref<string | null>(null)
const authLoading = ref(false)

// Upload state
const fetchTags = ref(false)
const selectedFiles = ref<File[]>([])
const uploading = ref(false)
const dragOver = ref(false)

interface UploadResult {
  file_name: string
  status: 'success' | 'error' | 'duplicate' | 'pending'
  id?: number
  existing_id?: number
  hash?: string
  message?: string
  tags_found?: boolean
  tags_applied?: number
  tags_error?: string
}

const uploadResults = ref<UploadResult[]>([])

const IMAGE_EXTENSIONS = [
  '.jpg',
  '.jpeg',
  '.png',
  '.gif',
  '.webp',
  '.bmp',
  '.tiff',
  '.tif',
  '.avif',
]
const VIDEO_EXTENSIONS = ['.mp4', '.webm', '.mov', '.avi', '.mkv', '.flv', '.wmv', '.m4v']
const ALL_EXTENSIONS = [...IMAGE_EXTENSIONS, ...VIDEO_EXTENSIONS]

const acceptString = ALL_EXTENSIONS.join(',')

/**
 * Persistent summary of the last upload, shown beneath the per-file list.
 *
 * The toasts report the same counts as they happen and then leave; this stays
 * so the outcome is still on screen once they have gone. Unlike the banner it
 * replaces, it does not require an empty file list — failed files stay
 * selected for retry, which meant the old summary was hidden in exactly the
 * case worth summarising.
 */
const uploadSummary = computed(() => {
  if (uploading.value || uploadResults.value.length === 0) return null

  const counts = { success: 0, duplicate: 0, error: 0 }
  for (const r of uploadResults.value) {
    if (r.status === 'success') counts.success++
    else if (r.status === 'duplicate') counts.duplicate++
    else if (r.status === 'error') counts.error++
  }
  if (counts.success + counts.duplicate + counts.error === 0) return null

  const parts: string[] = []
  if (counts.success > 0) parts.push(`${counts.success} uploaded`)
  if (counts.duplicate > 0) parts.push(`${counts.duplicate} skipped as duplicates`)
  if (counts.error > 0) parts.push(`${counts.error} failed`)
  const message = `${parts.join(', ')}.`

  if (counts.error > 0) {
    return { severity: 'danger' as const, title: 'Upload finished with errors', message }
  }
  if (counts.duplicate > 0) {
    return { severity: 'warning' as const, title: 'Upload complete', message }
  }
  return { severity: 'success' as const, title: 'Upload complete', message }
})

const hasFiles = computed(() => selectedFiles.value.length > 0)
const totalSize = computed(() => selectedFiles.value.reduce((sum, f) => sum + f.size, 0))

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
  } catch {
    authError.value = 'Invalid password'
  } finally {
    authLoading.value = false
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB'
}

function getFileExt(name: string): string {
  return '.' + (name.split('.').pop()?.toLowerCase() ?? '')
}

function isValidFile(file: File): boolean {
  return ALL_EXTENSIONS.includes(getFileExt(file.name))
}

// Track which GIF files are animated (keyed by "name:size" for uniqueness)
const animatedGifs = ref(new Set<string>())

function fileKey(file: File): string {
  return `${file.name}:${file.size}`
}

/**
 * Detect whether a GIF file is animated by scanning for multiple
 * Graphic Control Extension headers (0x21 0xF9 0x04) in the binary.
 */
async function checkAnimatedGif(file: File): Promise<boolean> {
  return new Promise((resolve) => {
    const reader = new FileReader()
    reader.onload = () => {
      const arr = new Uint8Array(reader.result as ArrayBuffer)
      let frames = 0
      for (let i = 0; i < arr.length - 2; i++) {
        if (arr[i] === 0x21 && arr[i + 1] === 0xf9 && arr[i + 2] === 0x04) {
          frames++
          if (frames > 1) {
            resolve(true)
            return
          }
        }
      }
      resolve(false)
    }
    reader.onerror = () => resolve(false)
    // Read up to 5 MB — enough to find multiple frames in any realistic GIF
    reader.readAsArrayBuffer(file.slice(0, 5 * 1024 * 1024))
  })
}

function fileIcon(file: File): string {
  const ext = getFileExt(file.name)
  if (VIDEO_EXTENSIONS.includes(ext)) return 'fa-solid fa-video'
  if (ext === '.gif' && animatedGifs.value.has(fileKey(file))) return 'fa-solid fa-video'
  return 'fa-solid fa-image'
}

function addFiles(fileList: FileList | File[]) {
  const files = Array.from(fileList)
  const valid: File[] = []
  const invalid: string[] = []

  for (const file of files) {
    if (isValidFile(file)) {
      // Avoid duplicates by name
      if (!selectedFiles.value.some((f) => f.name === file.name && f.size === file.size)) {
        valid.push(file)
      }
    } else {
      invalid.push(file.name)
    }
  }

  selectedFiles.value = [...selectedFiles.value, ...valid]

  // Check any new GIF files for animation in the background
  for (const file of valid) {
    if (getFileExt(file.name) === '.gif') {
      void checkAnimatedGif(file).then((animated) => {
        if (animated) {
          animatedGifs.value = new Set([...animatedGifs.value, fileKey(file)])
        }
      })
    }
  }

  if (invalid.length > 0) {
    toastStore.warning(
      `${invalid.length} file(s) were skipped because their file type is not supported.`,
      5000,
      'Unsupported Files',
    )
  }
}

function onDrop(e: DragEvent) {
  dragOver.value = false
  if (e.dataTransfer?.files) {
    addFiles(e.dataTransfer.files)
  }
}

function onDragOver(e: DragEvent) {
  e.preventDefault()
  dragOver.value = true
}

function onDragLeave() {
  dragOver.value = false
}

function onFileInput(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files) {
    addFiles(input.files)
    input.value = ''
  }
}

function removeFile(index: number) {
  animatedGifs.value.delete(fileKey(selectedFiles.value[index]))
  selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index)
}

function clearFiles() {
  selectedFiles.value = []
  uploadResults.value = []
  animatedGifs.value = new Set()
}

async function uploadFiles() {
  if (!hasFiles.value || uploading.value) return

  uploading.value = true
  uploadResults.value = selectedFiles.value.map((f) => ({
    file_name: f.name,
    status: 'pending' as const,
  }))

  const formData = new FormData()
  for (const file of selectedFiles.value) {
    formData.append('files[]', file)
  }
  if (fetchTags.value) {
    formData.append('fetch_tags', '1')
  }

  try {
    const result = await api.upload<{
      results: UploadResult[]
      total_uploaded: number
      total_duplicates: number
      total_failed: number
      total_tags_applied?: number
    }>(endpoints.media.upload, formData)
    if (!result) throw new Error('No response from the upload request')

    uploadResults.value = result.results

    if (result.total_uploaded > 0) {
      let msg = `${result.total_uploaded} file(s) uploaded successfully.`
      if (result.total_tags_applied != null && result.total_tags_applied > 0) {
        msg += ` ${result.total_tags_applied} tags applied.`
      }
      toastStore.success(msg, 4000, 'Upload Complete')
      await store.refreshTotals()
      await store.refreshTags()
    }
    if (result.total_duplicates > 0) {
      toastStore.warning(
        `${result.total_duplicates} file(s) were skipped because they already exist in the gallery.`,
        5000,
        'Duplicates Skipped',
      )
    }
    if (result.total_failed > 0) {
      toastStore.error(
        `${result.total_failed} file(s) could not be processed. Check the results below for details.`,
        8000,
        'Upload Failed',
      )
    }

    // Clear the file list — keep only failed items (duplicates and successes are resolved)
    const failedNames = new Set(
      result.results.filter((r) => r.status === 'error').map((r) => r.file_name),
    )
    selectedFiles.value = selectedFiles.value.filter((f) => failedNames.has(f.name))
  } catch (e) {
    toastStore.error(
      getErrorMessage(e, 'The upload request failed. Please try again.'),
      8000,
      'Upload Failed',
    )
    uploadResults.value = selectedFiles.value.map((f) => ({
      file_name: f.name,
      status: 'error' as const,
      message: 'Upload request failed',
    }))
  } finally {
    uploading.value = false
  }
}
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
              <p class="has-text-centered mb-4">Enter the admin password to access uploads.</p>
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
        <PageHeader
          title="Upload"
          :meta="`${store.totalMedia.toLocaleString()} items in gallery`"
        />

        <!-- Drop Zone -->
        <div
          class="drop-zone"
          :class="{ 'is-drag-over': dragOver, 'has-files': hasFiles }"
          role="button"
          tabindex="0"
          @drop.prevent="onDrop"
          @dragover.prevent="onDragOver"
          @dragleave="onDragLeave"
          @click="($refs.fileInput as HTMLInputElement).click()"
          @keydown.enter.prevent="($refs.fileInput as HTMLInputElement).click()"
          @keydown.space.prevent="($refs.fileInput as HTMLInputElement).click()"
        >
          <input
            ref="fileInput"
            aria-label="Choose files to upload"
            type="file"
            :accept="acceptString"
            multiple
            class="is-hidden"
            @change="onFileInput"
          />
          <div class="drop-zone-content">
            <span class="icon is-large has-text-grey">
              <i class="fa-solid fa-cloud-arrow-up fa-3x" />
            </span>
            <p class="is-size-5 has-text-grey mt-3">
              Drag &amp; drop files here, or click to browse
            </p>
            <p class="is-size-7 has-text-grey-light mt-1">Images, GIFs, and videos up to 500 MB</p>
          </div>
        </div>

        <!-- Attached to the drop zone rather than floating above it, since it
             changes what happens to the files you're about to drop. -->
        <label class="checkbox fetch-tags">
          <input v-model="fetchTags" type="checkbox" />
          <span>
            Fetch tags from Danbooru
            <span class="fetch-tags-hint">— looks each file up by its MD5 hash on upload</span>
          </span>
        </label>

        <!-- File List -->
        <div v-if="hasFiles" class="mt-5">
          <div class="level mb-3">
            <div class="level-left">
              <div class="level-item">
                <h3 class="title is-6 mb-0">
                  {{ selectedFiles.length }} file(s) selected
                  <span class="has-text-grey has-text-weight-normal"
                    >({{ formatSize(totalSize) }})</span
                  >
                </h3>
              </div>
            </div>
            <div class="level-right">
              <div class="level-item">
                <button
                  type="button"
                  class="button is-small"
                  :disabled="uploading"
                  @click="clearFiles"
                >
                  <span class="icon"><i class="fa-solid fa-xmark" /></span>
                  <span>Clear All</span>
                </button>
              </div>
            </div>
          </div>

          <div class="file-list">
            <div
              v-for="(file, index) in selectedFiles"
              :key="file.name + file.size"
              class="file-item"
            >
              <div class="file-item-info">
                <span class="icon has-text-grey-light mr-2">
                  <i :class="fileIcon(file)" />
                </span>
                <span class="file-item-name">{{ file.name }}</span>
                <span class="has-text-grey-light ml-2">({{ formatSize(file.size) }})</span>
              </div>
              <div class="file-item-status">
                <span
                  v-if="uploadResults[index]?.status === 'success'"
                  class="has-text-success is-size-7"
                >
                  <span class="icon"><i class="fa-solid fa-circle-check" /></span>
                  <template v-if="uploadResults[index]?.tags_applied">
                    {{ uploadResults[index].tags_applied }} tags
                  </template>
                  <template v-else-if="uploadResults[index]?.tags_found === false && fetchTags">
                    No tags found
                  </template>
                </span>
                <span
                  v-else-if="uploadResults[index]?.status === 'duplicate'"
                  class="has-text-warning is-size-7"
                >
                  <span class="icon"><i class="fa-solid fa-clone" /></span>
                  Duplicate
                </span>
                <span
                  v-else-if="uploadResults[index]?.status === 'error'"
                  class="has-text-danger is-size-7"
                >
                  <span class="icon"><i class="fa-solid fa-circle-xmark" /></span>
                  {{ uploadResults[index]?.message }}
                </span>
                <span
                  v-else-if="uploadResults[index]?.status === 'pending'"
                  class="icon has-text-grey"
                >
                  <i class="fa-solid fa-spinner fa-spin" />
                </span>
                <button
                  v-else
                  type="button"
                  class="delete is-small"
                  :disabled="uploading"
                  title="Remove"
                  @click="removeFile(index)"
                />
              </div>
            </div>
          </div>

          <!-- Upload Button -->
          <div class="mt-4">
            <button
              type="button"
              class="button is-primary is-medium"
              :class="{ 'is-loading': uploading }"
              :disabled="uploading || !hasFiles"
              @click="uploadFiles"
            >
              <span class="icon"><i class="fa-solid fa-upload" /></span>
              <span>Upload {{ selectedFiles.length }} File(s)</span>
            </button>
          </div>
        </div>

        <AppAlert
          v-if="uploadSummary"
          :severity="uploadSummary.severity"
          :title="uploadSummary.title"
          class="mt-5"
        >
          {{ uploadSummary.message }}
        </AppAlert>
      </template>
    </div>
  </section>
</template>

<style scoped>
.drop-zone {
  border: 2px dashed var(--border-strong);
  border-radius: var(--r-lg);
  padding: var(--sp-6) var(--sp-5);
  text-align: center;
  cursor: pointer;
  transition:
    border-color 0.2s,
    background 0.2s;
}

.drop-zone:hover {
  border-color: var(--text-3);
  background: var(--surface-1);
}

.drop-zone.is-drag-over {
  border-color: var(--success);
  background: var(--success-soft);
  border-style: solid;
}

.fetch-tags {
  display: flex;
  align-items: baseline;
  gap: var(--sp-2);
  margin-top: var(--sp-3);
  font-size: var(--t-md);
}

.fetch-tags-hint {
  color: var(--text-3);
}

.drop-zone-content {
  pointer-events: none;
}

.file-list {
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
}

.file-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--border);
}

.file-item:last-child {
  border-bottom: none;
}

.file-item-info {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 1;
}

.file-item-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.file-item-status {
  display: flex;
  align-items: center;
  margin-left: 1rem;
  flex-shrink: 0;
}
</style>
