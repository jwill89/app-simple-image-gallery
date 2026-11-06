import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type * as UseApiModule from '../composables/useApi'
import { ApiError } from '../composables/useApi'
import { useToastStore } from '../stores/toast'
import { useAuthStore } from '../stores/auth'
import type { TagImportStatus } from '../types'
import UploadView from '../views/UploadView.vue'

// The upload page against a fake server, which answers each request for the
// files it carries, or for only the first few, as PHP does past its limits.

const { upload, get } = vi.hoisted(() => ({ upload: vi.fn(), get: vi.fn() }))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({
      get,
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      del: vi.fn(),
      upload,
    }),
  }
})

const MB = 1024 * 1024

function images(count: number): File[] {
  return Array.from(
    { length: count },
    (_, i) => new File(['x'], `f${i}.jpg`, { type: 'image/jpeg' }),
  )
}

/** The server's answer for a request, covering only its first `received` files. */
function answer(form: FormData, received = Infinity) {
  const files = form.getAll('files[]') as File[]
  const results = files
    .slice(0, received)
    .map((file, i) => ({ file_name: file.name, status: 'success', id: 1000 + i }))
  return { results, total_uploaded: results.length, total_duplicates: 0, total_failed: 0 }
}

/** The answer with each file's Danbooru lookup queued, as when fetch_tags is sent. */
function queuedAnswer(form: FormData) {
  const response = answer(form)
  return {
    ...response,
    results: response.results.map((r) => ({ ...r, tags_status: 'queued' })),
  }
}

/** GET /media/tag-imports answers with `statusFor` each item asked about. */
function importsAnswer(statusFor: (id: number, poll: number) => Partial<TagImportStatus>) {
  let poll = 0
  get.mockImplementation((url: string) => {
    if (!url.startsWith('/media/tag-imports')) {
      return Promise.resolve(undefined)
    }
    poll++
    const ids = (new URLSearchParams(url.split('?')[1]).get('ids') ?? '').split(',').map(Number)
    return Promise.resolve(
      ids.map((id) => ({
        media_id: id,
        status: 'queued',
        tags_applied: 0,
        error: null,
        ...statusFor(id, poll),
      })),
    )
  })
}

const importPolls = () =>
  get.mock.calls.filter(([url]) => String(url).startsWith('/media/tag-imports')).length

const messages = () => useToastStore().toasts.map((t) => t.message)

const sent = (call: number) => (upload.mock.calls[call]![1] as FormData).getAll('files[]').length

let pinia: Pinia

async function uploadThrough(files: File[], { fetchTags = false } = {}) {
  const wrapper = mount(UploadView, { global: { plugins: [pinia], stubs: { PageHeader: true } } })
  if (fetchTags) {
    await wrapper.get('input[type="checkbox"]').setValue(true)
  }
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
  await wrapper.get('button.is-primary').trigger('click')
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  // The page is an admin's.
  useAuthStore().user = { id: 1, username: 'admin', is_admin: true, created_at: '' }
  upload.mockReset()
  get.mockReset()
  get.mockResolvedValue(undefined)
  // Only the clock the polling waits on: flushPromises still needs setImmediate.
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
})

afterEach(() => {
  vi.useRealTimers()
})

/** Let the tag-import poll come round `times` times. */
async function poll(times = 1) {
  for (let i = 0; i < times; i++) {
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
  }
}

describe('UploadView', () => {
  it('sends 28 files in two requests and reports all 28', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(answer(form)))

    const wrapper = await uploadThrough(images(28))

    expect(upload).toHaveBeenCalledTimes(2)
    expect([sent(0), sent(1)]).toEqual([20, 8])
    expect(wrapper.text()).toContain('28 uploaded.')
    expect(wrapper.find('.file-list').exists()).toBe(false)
    expect(useToastStore().toasts.map((t) => t.message)).toEqual([
      '28 file(s) uploaded successfully.',
    ])
  })

  it('fails a file the server did not answer for, and keeps it selected', async () => {
    upload
      .mockImplementationOnce((_url: string, form: FormData) => Promise.resolve(answer(form, 18)))
      .mockImplementation((_url: string, form: FormData) => Promise.resolve(answer(form)))

    const wrapper = await uploadThrough(images(28))

    expect(wrapper.text()).toContain('26 uploaded, 2 failed.')
    const left = wrapper.findAll('.file-item')
    expect(left.map((row) => row.get('.file-item-name').text())).toEqual(['f18.jpg', 'f19.jpg'])
    expect(left[0]!.text()).toContain('The server did not receive this file')
    expect(useToastStore().toasts.map((t) => t.message)).toContain(
      '2 file(s) could not be uploaded. Check the results below for details.',
    )
  })

  it("fails a failed request's files with its message, and sends the rest", async () => {
    upload
      .mockImplementationOnce((_url: string, form: FormData) => Promise.resolve(answer(form)))
      .mockRejectedValueOnce(new ApiError(503, 'ServerBusy', 'The server is busy.'))

    const wrapper = await uploadThrough(images(28))

    expect(wrapper.text()).toContain('20 uploaded, 8 failed.')
    expect(wrapper.findAll('.file-item')).toHaveLength(8)
    expect(wrapper.get('.file-item').text()).toContain('The server is busy.')
    expect(useToastStore().toasts.map((t) => t.message)).toContain(
      '8 file(s) could not be uploaded. The server is busy.',
    )
  })

  it('reports the tags applied, and the files with no post, once the lookups have run', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(queuedAnswer(form)))
    importsAnswer((id) =>
      id === 1000 ? { status: 'applied', tags_applied: 12 } : { status: 'not_found' },
    )

    const wrapper = await uploadThrough(images(3), { fetchTags: true })

    expect(messages()).toEqual(['3 file(s) uploaded successfully. Fetching their Danbooru tags…'])
    expect(wrapper.text()).toContain('3 uploaded. Fetching Danbooru tags for 3 of them…')

    await poll()

    expect(messages()).toEqual([
      '3 file(s) uploaded successfully. Fetching their Danbooru tags…',
      '12 Danbooru tags applied to 1 file(s).',
      'Danbooru has no post for 2 file(s), so they were uploaded without tags.',
    ])
    expect(wrapper.text()).toContain('3 uploaded. 2 have no post on Danbooru.')
    expect(wrapper.text()).not.toContain('Fetching Danbooru tags')
  })

  it("warns, until dismissed, of a failed Danbooru lookup with the server's reason", async () => {
    const blocked = "Danbooru's firewall (Cloudflare) blocked this server's request."
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(queuedAnswer(form)))
    importsAnswer(() => ({ status: 'failed', error: blocked }))

    const wrapper = await uploadThrough(images(3), { fetchTags: true })
    await poll()

    const toasts = useToastStore().toasts
    expect(toasts.map((t) => t.message)).toContain(
      `Danbooru tags could not be fetched for 3 file(s). ${blocked}`,
    )
    expect(toasts.find((t) => t.title === 'Tags Not Fetched')?.duration).toBe(0)
    expect(wrapper.text()).toContain(
      '3 uploaded. Danbooru tags could not be fetched for 3 of them.',
    )
  })

  it('keeps asking while lookups are still queued, and only about those', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(queuedAnswer(form)))
    importsAnswer((id, n) =>
      id === 1000 || n > 1 ? { status: 'applied', tags_applied: 2 } : { status: 'queued' },
    )

    await uploadThrough(images(2), { fetchTags: true })
    await poll(3)

    expect(importPolls()).toBe(2)
    expect(get).toHaveBeenLastCalledWith('/media/tag-imports?ids=1001')
    expect(messages()).toContain('4 Danbooru tags applied to 2 file(s).')
  })

  it('stops asking after five minutes, and says the lookups are still running', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(queuedAnswer(form)))
    importsAnswer(() => ({ status: 'queued' }))

    await uploadThrough(images(2), { fetchTags: true })
    await poll(151)

    expect(importPolls()).toBe(150)
    expect(messages()).toContain(
      "Danbooru tags are still being fetched for 2 file(s); they will show on each file's page once done.",
    )
  })

  it('warns when the server could not queue the lookups because Danbooru is not set up there', async () => {
    const notSetUp = 'Danbooru is not set up on the server (DANBOORU_LOGIN and DANBOORU_API_KEY).'
    upload.mockImplementation((_url: string, form: FormData) => {
      const response = answer(form)
      return Promise.resolve({
        ...response,
        results: response.results.map((r) => ({ ...r, tags_error: notSetUp })),
      })
    })

    const wrapper = await uploadThrough(images(2), { fetchTags: true })
    await poll()

    expect(messages()).toContain(`Danbooru tags could not be fetched for 2 file(s). ${notSetUp}`)
    expect(wrapper.text()).toContain('Danbooru tags could not be fetched for 2 of them.')
    expect(importPolls()).toBe(0)
  })

  it('says nothing about tags when none were asked for', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(answer(form)))

    await uploadThrough(images(2))

    expect(useToastStore().toasts.map((t) => t.message)).toEqual([
      '2 file(s) uploaded successfully.',
    ])
  })

  it('does not send a file over the size limit', async () => {
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(answer(form)))
    const files = images(3)
    Object.defineProperty(files[1], 'size', { value: 101 * MB })

    const wrapper = await uploadThrough(files)

    expect(upload).toHaveBeenCalledTimes(1)
    expect(sent(0)).toBe(2)
    expect(wrapper.text()).toContain('2 uploaded, 1 failed.')
    expect(wrapper.get('.file-item').text()).toContain('Larger than 100 MB')
  })
})
