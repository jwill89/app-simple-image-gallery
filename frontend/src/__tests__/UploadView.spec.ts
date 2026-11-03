import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type * as UseApiModule from '../composables/useApi'
import { ApiError } from '../composables/useApi'
import { useToastStore } from '../stores/toast'
import UploadView from '../views/UploadView.vue'

// The upload page against a fake server, which answers each request for the
// files it carries, or for only the first few, as PHP does past its limits.

const { upload } = vi.hoisted(() => ({ upload: vi.fn() }))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    hasAuthToken: () => true,
    useApi: () => ({
      get: vi.fn(() => Promise.resolve(undefined)),
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

const sent = (call: number) => (upload.mock.calls[call][1] as FormData).getAll('files[]').length

let pinia: Pinia

async function uploadThrough(files: File[], { fetchTags = false } = {}) {
  const wrapper = mount(UploadView, { global: { plugins: [pinia], stubs: { PageHeader: true } } })
  if (fetchTags) await wrapper.get('input[type="checkbox"]').setValue(true)
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
  upload.mockReset()
})

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
    expect(left[0].text()).toContain('The server did not receive this file')
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

  it("warns, until dismissed, of a failed Danbooru lookup with the server's reason", async () => {
    const blocked = "Danbooru's firewall (Cloudflare) blocked this server's request."
    upload.mockImplementation((_url: string, form: FormData) => {
      const response = answer(form)
      return Promise.resolve({
        ...response,
        total_tags_applied: 0,
        results: response.results.map((r) => ({ ...r, tags_error: blocked })),
      })
    })

    const wrapper = await uploadThrough(images(3), { fetchTags: true })

    const toasts = useToastStore().toasts
    expect(toasts.map((t) => t.message)).toEqual([
      '3 file(s) uploaded successfully.',
      `Danbooru tags could not be fetched for 3 file(s). ${blocked}`,
    ])
    expect(toasts[1].duration).toBe(0)
    expect(wrapper.text()).toContain(
      '3 uploaded. Danbooru tags could not be fetched for 3 of them.',
    )
  })

  it('warns when the server skipped the lookups because Danbooru is not set up there', async () => {
    // No total_tags_applied: the server's sign that it ignored fetch_tags.
    upload.mockImplementation((_url: string, form: FormData) => Promise.resolve(answer(form)))

    const wrapper = await uploadThrough(images(2), { fetchTags: true })

    expect(useToastStore().toasts.map((t) => t.message)).toContain(
      'Danbooru tags could not be fetched for 2 file(s). Danbooru is not set up on the server.',
    )
    expect(wrapper.text()).toContain('Danbooru tags could not be fetched for 2 of them.')
  })

  it('says which files Danbooru has no post for, apart from failures', async () => {
    upload.mockImplementation((_url: string, form: FormData) => {
      const response = answer(form)
      return Promise.resolve({
        ...response,
        total_tags_applied: 12,
        results: response.results.map((r, i) =>
          i === 0 ? { ...r, tags_found: true, tags_applied: 12 } : { ...r, tags_found: false },
        ),
      })
    })

    const wrapper = await uploadThrough(images(3), { fetchTags: true })

    expect(useToastStore().toasts.map((t) => t.message)).toEqual([
      '3 file(s) uploaded successfully. 12 tags applied.',
      'Danbooru has no post for 2 file(s), so they were uploaded without tags.',
    ])
    expect(wrapper.text()).toContain('3 uploaded. 2 have no post on Danbooru.')
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
