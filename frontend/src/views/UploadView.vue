<script setup lang="ts">
import { ref, computed } from 'vue'
import { useApi, getErrorMessage } from '../composables/useApi'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import { UPLOAD_LIMITS, planUploadBatches } from '../constants/uploads'
import type { TagImportStatus } from '../types'
import AppAlert from '../components/AppAlert.vue'
import PageHeader from '../components/PageHeader.vue'
import SignInGate from '../components/SignInGate.vue'

const api = useApi()
const store = useGalleryStore()
const toastStore = useToastStore()

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
  /**
   * Where the file's Danbooru lookup stands: queued by the upload, then as
   * GET /media/tag-imports reports it once the queue has run it.
   */
  tags_status?: 'queued' | 'applied' | 'not_found' | 'failed'
  tags_applied?: number
  /** Why the file has no Danbooru tags: not set up on the server, or the lookup failed. */
  tags_error?: string
}

interface UploadResponse {
  results: UploadResult[]
  total_uploaded: number
  total_duplicates: number
  total_failed: number
}

/** How often to ask how the queued Danbooru lookups went, and for how long at most. */
const TAG_POLL_INTERVAL_MS = 2000
const TAG_POLL_TIMEOUT_MS = 5 * 60 * 1000
/** The most items one GET /media/tag-imports asks about (the API's limit). */
const TAG_POLL_BATCH = 200

/** One per selected file, by position; empty until an upload starts. */
const uploadResults = ref<UploadResult[]>([])
/**
 * Every file's result from the last upload. Only the failed files stay
 * selected afterwards, so the list alone can't say what happened to the rest.
 */
const lastUploadResults = ref<UploadResult[]>([])
/** True while the last upload's Danbooru lookups are still being waited on. */
const fetchingTags = ref(false)
/** Which upload is the latest; a poll for an earlier one leaves the summary alone. */
let uploadRun = 0

const maxFileSizeLabel = `${UPLOAD_LIMITS.bytesPerFile / (1024 * 1024)} MB`

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
  if (uploading.value || lastUploadResults.value.length === 0) return null

  const counts = { success: 0, duplicate: 0, error: 0, tagsFailed: 0, noPost: 0, fetching: 0 }
  for (const r of lastUploadResults.value) {
    if (r.status === 'success') counts.success++
    else if (r.status === 'duplicate') counts.duplicate++
    else if (r.status === 'error') counts.error++
    if (r.status === 'success' && r.tags_error) counts.tagsFailed++
    if (r.status === 'success' && r.tags_status === 'not_found') counts.noPost++
    if (r.status === 'success' && r.tags_status === 'queued' && fetchingTags.value) {
      counts.fetching++
    }
  }
  if (counts.success + counts.duplicate + counts.error === 0) return null

  const parts: string[] = []
  if (counts.success > 0) parts.push(`${counts.success} uploaded`)
  if (counts.duplicate > 0) parts.push(`${counts.duplicate} skipped as duplicates`)
  if (counts.error > 0) parts.push(`${counts.error} failed`)
  let message = `${parts.join(', ')}.`
  // Uploaded, but without the tags asked for: the toasts that said so have gone.
  if (counts.tagsFailed > 0) {
    message += ` Danbooru tags could not be fetched for ${counts.tagsFailed} of them.`
  }
  if (counts.noPost > 0) {
    message += ` ${counts.noPost} ${counts.noPost === 1 ? 'has' : 'have'} no post on Danbooru.`
  }
  if (counts.fetching > 0) {
    message += ` Fetching Danbooru tags for ${counts.fetching} of them…`
  }

  if (counts.error > 0) {
    return { severity: 'danger' as const, title: 'Upload finished with errors', message }
  }
  if (counts.tagsFailed > 0) {
    return { severity: 'warning' as const, title: 'Uploaded without some tags', message }
  }
  if (counts.duplicate > 0) {
    return { severity: 'warning' as const, title: 'Upload complete', message }
  }
  return { severity: 'success' as const, title: 'Upload complete', message }
})

const hasFiles = computed(() => selectedFiles.value.length > 0)
const totalSize = computed(() => selectedFiles.value.reduce((sum, f) => sum + f.size, 0))

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
  uploadResults.value = uploadResults.value.filter((_, i) => i !== index)
}

function clearFiles() {
  selectedFiles.value = []
  uploadResults.value = []
  lastUploadResults.value = []
  animatedGifs.value = new Set()
}

/**
 * Sends the selected files in as many requests as the server's limits need
 * (constants/uploads.ts), one after another, and fills in each file's result
 * as its request comes back. A file the server didn't answer for, or that was
 * too large to send, fails with the reason instead of going missing.
 */
async function uploadFiles() {
  if (!hasFiles.value || uploading.value) return

  uploading.value = true
  const run = ++uploadRun
  fetchingTags.value = false
  const files = selectedFiles.value
  const wantTags = fetchTags.value
  let queued: number[] = []
  const failWith = (index: number, message: string): UploadResult => ({
    file_name: files[index].name,
    status: 'error',
    message,
  })
  uploadResults.value = files.map((f) => ({ file_name: f.name, status: 'pending' as const }))

  try {
    const plan = planUploadBatches(files)
    for (const index of plan.tooLarge) {
      uploadResults.value[index] = failWith(index, `Larger than ${maxFileSizeLabel}`)
    }

    let requestError: string | null = null
    for (const batch of plan.batches) {
      const formData = new FormData()
      for (const index of batch) {
        formData.append('files[]', files[index])
      }
      if (wantTags) {
        formData.append('fetch_tags', '1')
      }

      try {
        const result = await api.upload<UploadResponse>(endpoints.media.upload, formData)
        if (!result) throw new Error('No response from the upload request')

        batch.forEach((index, n) => {
          uploadResults.value[index] =
            n < result.results.length
              ? result.results[n]
              : failWith(index, 'The server did not receive this file')
        })
      } catch (e) {
        const message = getErrorMessage(e, 'The upload request failed. Please try again.')
        requestError ??= message
        for (const index of batch) {
          uploadResults.value[index] = failWith(index, message)
        }
      }
    }

    const results = uploadResults.value.slice(0, files.length)
    const count = (status: UploadResult['status']) =>
      results.filter((r) => r.status === status).length
    const uploaded = count('success')
    const duplicates = count('duplicate')
    const failed = count('error')
    // Files added without the Danbooru tags asked for, because the server has
    // no Danbooru account to ask with. Queued lookups are reported as they finish.
    const tagErrors = results.flatMap((r) =>
      r.status === 'success' && r.tags_error ? [r.tags_error] : [],
    )
    queued = results.flatMap((r) =>
      wantTags && r.status === 'success' && r.tags_status === 'queued' && r.id !== undefined
        ? [r.id]
        : [],
    )

    // Keep only the failed files, with their results, for a retry; successes
    // and duplicates are done. Files added while uploading stay too.
    lastUploadResults.value = results
    const keep = results.flatMap((r, index) => (r.status === 'error' ? [index] : []))
    selectedFiles.value = [
      ...keep.map((index) => files[index]),
      ...selectedFiles.value.slice(files.length),
    ]
    uploadResults.value = keep.map((index) => results[index])

    if (uploaded > 0) {
      let msg = `${uploaded} file(s) uploaded successfully.`
      if (queued.length > 0) {
        msg += ' Fetching their Danbooru tags…'
      }
      toastStore.success(msg, 4000, 'Upload Complete')
    }
    if (tagErrors.length > 0) {
      // Stays until dismissed: the files are in, so nothing else says their
      // tags are missing, and the reason (a Ray ID, a setting) is to act on.
      toastStore.warning(
        `Danbooru tags could not be fetched for ${tagErrors.length} file(s). ${tagErrors[0]}`,
        0,
        'Tags Not Fetched',
      )
    }
    if (duplicates > 0) {
      toastStore.warning(
        `${duplicates} file(s) were skipped because they already exist in the gallery.`,
        5000,
        'Duplicates Skipped',
      )
    }
    if (failed > 0) {
      toastStore.error(
        `${failed} file(s) could not be uploaded. ${requestError ?? 'Check the results below for details.'}`,
        8000,
        'Upload Failed',
      )
    }
    if (uploaded > 0) {
      await store.refreshTotals()
    }
  } finally {
    uploading.value = false
  }

  if (queued.length > 0) await followTagImports(run, queued)
}

/** A file's result once the queue has run its Danbooru lookup. */
function settle(result: UploadResult, status: TagImportStatus): UploadResult {
  switch (status.status) {
    case 'applied':
      return { ...result, tags_status: 'applied', tags_applied: status.tags_applied }
    case 'not_found':
      return { ...result, tags_status: 'not_found' }
    case 'failed':
      return {
        ...result,
        tags_status: 'failed',
        tags_error: status.error ?? 'The Danbooru lookup failed.',
      }
    case 'queued':
      return result
    case 'none': {
      // Nothing is known of it any more, so nothing more is said about it.
      const { tags_status: _dropped, ...rest } = result
      return rest
    }
  }
}

/**
 * The upload only queues each file's Danbooru lookup, so its outcome comes
 * later: ask every few seconds until every lookup has finished (or a few
 * minutes have passed), keeping the summary current, then report the outcome.
 */
async function followTagImports(run: number, ids: number[]) {
  const waiting = new Set(ids)
  const settled: TagImportStatus[] = []
  const deadline = Date.now() + TAG_POLL_TIMEOUT_MS
  fetchingTags.value = true

  while (waiting.size > 0 && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, TAG_POLL_INTERVAL_MS))
    const asked = [...waiting]
    const done: TagImportStatus[] = []
    for (let i = 0; i < asked.length; i += TAG_POLL_BATCH) {
      try {
        const statuses = await api.get<TagImportStatus[]>(
          endpoints.media.tagImports(asked.slice(i, i + TAG_POLL_BATCH)),
        )
        done.push(...(statuses ?? []).filter((s) => s.status !== 'queued'))
      } catch {
        // A failed poll is asked again next time round.
      }
    }
    for (const status of done) {
      waiting.delete(status.media_id)
      settled.push(status)
    }
    if (run === uploadRun && done.length > 0) {
      const byId = new Map(done.map((status) => [status.media_id, status]))
      lastUploadResults.value = lastUploadResults.value.map((r) => {
        const status = r.id === undefined ? undefined : byId.get(r.id)
        return status ? settle(r, status) : r
      })
    }
  }

  if (run === uploadRun) fetchingTags.value = false
  reportTagImports(settled, waiting.size)
}

function reportTagImports(settled: TagImportStatus[], stillWaiting: number) {
  const applied = settled.filter((s) => s.status === 'applied')
  const failed = settled.filter((s) => s.status === 'failed')
  const notFound = settled.filter((s) => s.status === 'not_found').length

  if (applied.length > 0) {
    const tags = applied.reduce((sum, s) => sum + s.tags_applied, 0)
    toastStore.success(
      `${tags} Danbooru tags applied to ${applied.length} file(s).`,
      4000,
      'Tags Applied',
    )
  }
  if (failed.length > 0) {
    // Stays until dismissed: the files are in, so nothing else says their
    // tags are missing, and the reason (a Ray ID, a setting) is to act on.
    const reason = failed[0].error ?? 'The Danbooru lookup failed.'
    toastStore.warning(
      `Danbooru tags could not be fetched for ${failed.length} file(s). ${reason}`,
      0,
      'Tags Not Fetched',
    )
  }
  if (notFound > 0) {
    toastStore.info(
      `Danbooru has no post for ${notFound} file(s), so they were uploaded without tags.`,
      6000,
      'No Tags Found',
    )
  }
  if (stillWaiting > 0) {
    toastStore.info(
      `Danbooru tags are still being fetched for ${stillWaiting} file(s); they will show on each file's page once done.`,
      6000,
      'Tags Still Fetching',
    )
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <SignInGate admin>
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
            <p class="is-size-7 has-text-grey-light mt-1">
              Images, GIFs, and videos up to {{ maxFileSizeLabel }} each
            </p>
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
                  <template v-if="uploadResults[index]?.tags_error">Tags not fetched</template>
                  <template v-else-if="uploadResults[index]?.tags_status === 'queued'">
                    Fetching tags
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
      </SignInGate>
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
