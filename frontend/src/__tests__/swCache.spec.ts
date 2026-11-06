import { beforeEach, describe, expect, it, vi } from 'vitest'
import type * as CacheModule from '../sw/cache'

// The service worker's caching (src/sw/cache.ts), against an in-memory
// CacheStorage and a fake network.

function urlOf(request: RequestInfo | URL): string {
  if (typeof request === 'string') {
    return new URL(request, location.origin).href
  }
  return request instanceof URL ? request.href : request.url
}

/** A Cache that keeps entries in insertion order, as the real one lists them. */
class FakeCache {
  readonly entries = new Map<string, Response>()
  keyListings = 0

  match(request: RequestInfo | URL): Promise<Response | undefined> {
    return Promise.resolve(this.entries.get(urlOf(request))?.clone())
  }

  put(request: RequestInfo | URL, response: Response): Promise<void> {
    const url = urlOf(request)
    this.entries.delete(url)
    this.entries.set(url, response)
    return Promise.resolve()
  }

  keys(): Promise<Request[]> {
    this.keyListings++
    return Promise.resolve([...this.entries.keys()].map((url) => new Request(url)))
  }

  delete(request: RequestInfo | URL): Promise<boolean> {
    return Promise.resolve(this.entries.delete(urlOf(request)))
  }
}

class FakeCacheStorage {
  readonly byName = new Map<string, FakeCache>()

  open(name: string): Promise<FakeCache> {
    const cache = this.byName.get(name) ?? new FakeCache()
    this.byName.set(name, cache)
    return Promise.resolve(cache)
  }

  keys(): Promise<string[]> {
    return Promise.resolve([...this.byName.keys()])
  }

  delete(name: string): Promise<boolean> {
    return Promise.resolve(this.byName.delete(name))
  }
}

const thumb = (name: string) => `${location.origin}/media/thumbs/${name}.webp`
const network = vi.fn<(request: RequestInfo | URL) => Promise<Response>>()

let sw: typeof CacheModule
let storage: FakeCacheStorage
let kept: Promise<unknown>[]
const waitUntil = (work: Promise<unknown>) => {
  kept.push(work)
}
const thumbnails = () => storage.open(sw.THUMB_CACHE)

beforeEach(async () => {
  // The module keeps its downloads and queue in module state: start each test fresh.
  vi.resetModules()
  storage = new FakeCacheStorage()
  vi.stubGlobal('caches', storage)
  network.mockReset()
  network.mockImplementation(() => Promise.resolve(new Response('image')))
  vi.stubGlobal('fetch', network)
  kept = []
  sw = await import('../sw/cache')
})

describe('cacheFor', () => {
  it('caches thumbnails and the static assets, and leaves the rest to the network', () => {
    const at = (path: string, init?: RequestInit) =>
      sw.cacheFor(new Request(`${location.origin}${path}`, init))
    expect(at('/media/thumbs/a.webp')).toBe(sw.THUMB_CACHE)
    expect(at('/assets/index-abc.js')).toBe(sw.STATIC_CACHE)
    expect(at('/favicon.png')).toBe(sw.STATIC_CACHE)
    expect(at('/api/media/page/1/40')).toBeNull()
    expect(at('/api/media?cursor=x')).toBeNull()
    expect(at('/media/full/a.jpg')).toBeNull()
    expect(at('/media/thumbs/a.webp', { method: 'POST' })).toBeNull()
    expect(sw.cacheFor(new Request('https://elsewhere.example/media/thumbs/a.webp'))).toBeNull()
  })
})

describe('cacheFirst', () => {
  it('answers from the cache without going to the network', async () => {
    ;(await thumbnails()).entries.set(thumb('a'), new Response('cached'))

    const response = await sw.cacheFirst(new Request(thumb('a')), sw.THUMB_CACHE, waitUntil)

    expect(await response.text()).toBe('cached')
    expect(network).not.toHaveBeenCalled()
  })

  it('downloads what is missing and keeps it for next time', async () => {
    const first = await sw.cacheFirst(new Request(thumb('a')), sw.THUMB_CACHE, waitUntil)
    await Promise.all(kept)
    const second = await sw.cacheFirst(new Request(thumb('a')), sw.THUMB_CACHE, waitUntil)

    expect(await first.text()).toBe('image')
    expect(await second.text()).toBe('image')
    expect(network).toHaveBeenCalledTimes(1)
  })

  it('does not keep a failed download', async () => {
    network.mockResolvedValueOnce(new Response('', { status: 404 }))

    const response = await sw.cacheFirst(new Request(thumb('a')), sw.THUMB_CACHE, waitUntil)
    await Promise.all(kept)

    expect(response.status).toBe(404)
    expect((await thumbnails()).entries.size).toBe(0)
  })

  it('waits for a download the prefetch has under way instead of starting another', async () => {
    let finish: (response: Response) => void = () => undefined
    network.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve
        }),
    )
    const prefetch = sw.prefetchThumbnails(['/media/thumbs/a.webp'])
    await vi.waitFor(() => {
      expect(network).toHaveBeenCalledTimes(1)
    })

    const page = sw.cacheFirst(new Request(thumb('a')), sw.THUMB_CACHE, waitUntil)
    finish(new Response('prefetched'))

    expect(await (await page).text()).toBe('prefetched')
    await prefetch
    expect(network).toHaveBeenCalledTimes(1)
  })

  it('answers an image it can neither find nor download with a blank pixel', async () => {
    network.mockRejectedValue(new TypeError('offline'))
    const image = new Request(thumb('a'))
    Object.defineProperty(image, 'destination', { value: 'image' })

    const response = await sw.cacheFirst(image, sw.THUMB_CACHE, waitUntil)

    expect(response.headers.get('Content-Type')).toBe('image/gif')
    await expect(
      sw.cacheFirst(new Request(`${location.origin}/assets/index.js`), sw.STATIC_CACHE, waitUntil),
    ).rejects.toThrow('offline')
  })
})

describe('prefetchThumbnails', () => {
  it('downloads each thumbnail once, skipping the ones already cached', async () => {
    ;(await thumbnails()).entries.set(thumb('a'), new Response('cached'))

    await sw.prefetchThumbnails([
      '/media/thumbs/a.webp',
      '/media/thumbs/b.webp',
      '/media/thumbs/b.webp',
    ])

    expect(network.mock.calls.map(([request]) => urlOf(request))).toEqual([thumb('b')])
    expect((await thumbnails()).entries.has(thumb('b'))).toBe(true)
  })

  it('has at most six downloads under way, leaving room for the page', async () => {
    let open = 0
    let most = 0
    const finishers: (() => void)[] = []
    network.mockImplementation(() => {
      most = Math.max(most, ++open)
      return new Promise((resolve) => {
        finishers.push(() => {
          open--
          resolve(new Response('image'))
        })
      })
    })

    const done = sw.prefetchThumbnails(
      Array.from({ length: 20 }, (_, i) => `/media/thumbs/${String(i)}.webp`),
    )
    for (let round = 0; round < 10; round++) {
      await new Promise((resolve) => setTimeout(resolve, 0))
      for (const finish of finishers.splice(0)) {
        finish()
      }
    }
    await done

    expect(network).toHaveBeenCalledTimes(20)
    expect(most).toBe(6)
  })

  it('trims the cache to the newest 2000 once per hundred downloads, not after each', async () => {
    const cache = await thumbnails()
    for (let i = 0; i < 2000; i++) {
      cache.entries.set(thumb(`old-${String(i)}`), new Response(''))
    }

    await sw.prefetchThumbnails(
      Array.from({ length: 100 }, (_, i) => `/media/thumbs/new-${String(i)}.webp`),
    )
    await vi.waitFor(() => {
      expect(cache.entries.size).toBe(2000)
    })

    expect(cache.keyListings).toBe(1)
    expect(cache.entries.has(thumb('old-99'))).toBe(false)
    expect(cache.entries.has(thumb('old-100'))).toBe(true)
    expect(cache.entries.has(thumb('new-99'))).toBe(true)
  })
})

describe('removeOldCaches', () => {
  it("deletes the caches this version doesn't name", async () => {
    for (const name of ['gallery-api-v4', 'gallery-thumbs-v3', sw.THUMB_CACHE, sw.STATIC_CACHE]) {
      await storage.open(name)
    }

    await sw.removeOldCaches()

    expect(await storage.keys()).toEqual([sw.THUMB_CACHE, sw.STATIC_CACHE])
  })
})
