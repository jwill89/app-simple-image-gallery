import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, getFieldError, useApi, whenUnauthorized } from '../composables/useApi'

interface MockResponse {
  ok?: boolean
  status?: number
  jsonData?: unknown
}

function mockFetch(response: MockResponse = {}) {
  const fetchMock = vi.fn().mockResolvedValue({
    ok: response.ok ?? true,
    status: response.status ?? 200,
    json: async () => response.jsonData ?? {},
    text: async () => JSON.stringify(response.jsonData ?? {}),
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('useApi', () => {
  beforeEach(() => {
    whenUnauthorized(() => undefined)
  })

  it('GET returns parsed JSON and prefixes the /api base path', async () => {
    const fetchMock = mockFetch({ jsonData: { hello: 'world' } })
    const result = await useApi().get<{ hello: string }>('/test/')

    expect(result).toEqual({ hello: 'world' })
    expect(fetchMock).toHaveBeenCalledWith('/api/test/', expect.any(Object))
  })

  it('POST sends a JSON body with the right method and content type', async () => {
    const fetchMock = mockFetch({ jsonData: { id: 1 } })
    await useApi().post('/create/', { name: 'x' })

    const [, opts] = fetchMock.mock.calls[0]
    expect(opts.method).toBe('POST')
    expect(opts.body).toBe(JSON.stringify({ name: 'x' }))
    expect((opts.headers as Record<string, string>)['Content-Type']).toBe('application/json')
  })

  it('sends the session cookie, and no token of its own', async () => {
    const fetchMock = mockFetch()
    await useApi().get('/protected/')

    const [, opts] = fetchMock.mock.calls[0]
    expect(opts.credentials).toBe('same-origin')
    expect(opts.headers).toBeUndefined()
  })

  it('uploads a form as a POST, leaving its content type to the browser', async () => {
    const fetchMock = mockFetch({ jsonData: { ok: true } })
    const form = new FormData()
    await useApi().upload('/media', form)

    const [, opts] = fetchMock.mock.calls[0]
    expect(opts).toMatchObject({ method: 'POST', body: form, credentials: 'same-origin' })
    expect(opts.headers).toBeUndefined()
  })

  it('resolves to undefined on a 204 No Content (e.g. DELETE)', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 204,
      text: async () => '',
      json: async () => {
        throw new Error('no body')
      },
    })
    vi.stubGlobal('fetch', fetchMock)

    const result = await useApi().del('/media/5')
    expect(result).toBeUndefined()
    const [, opts] = fetchMock.mock.calls[0]
    expect(opts.method).toBe('DELETE')
  })

  it('throws a structured ApiError that reads the JSON error body', async () => {
    mockFetch({ ok: false, status: 404, jsonData: { error: 'MediaNotFound', message: 'no media' } })

    await expect(useApi().get('/missing/')).rejects.toMatchObject({
      name: 'ApiError',
      status: 404,
      code: 'MediaNotFound',
      message: 'no media',
    })
  })

  it('keeps the messages of a failed validation by field', async () => {
    mockFetch({
      ok: false,
      status: 400,
      jsonData: {
        error: 'ValidationFailed',
        message: 'The tag name has already been taken.',
        errors: { tag_name: ['The tag name has already been taken.'] },
      },
    })

    const error: unknown = await useApi()
      .post('/tags', { tag_name: 'x' })
      .catch((e: unknown) => e)
    expect(getFieldError(error, 'tag_name')).toBe('The tag name has already been taken.')
    expect(getFieldError(error, 'category_id')).toBe('')
    expect(getFieldError(new Error('plain'), 'tag_name')).toBe('')
  })

  it('falls back to a status-based message when the body has none', async () => {
    mockFetch({ ok: false, status: 403, jsonData: {} })

    await expect(useApi().get('/forbidden/')).rejects.toMatchObject({
      status: 403,
      message: 'You do not have permission to perform this action.',
    })
  })

  it('reports a 401 as the session ending, and still throws it', async () => {
    const ended = vi.fn()
    whenUnauthorized(ended)
    mockFetch({ ok: false, status: 401 })

    await expect(useApi().get('/protected/')).rejects.toBeInstanceOf(ApiError)
    expect(ended).toHaveBeenCalledOnce()

    mockFetch({ ok: false, status: 403 })
    await expect(useApi().get('/forbidden/')).rejects.toBeInstanceOf(ApiError)
    expect(ended).toHaveBeenCalledOnce()
  })
})
