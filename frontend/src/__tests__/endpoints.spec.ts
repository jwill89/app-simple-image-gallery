import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { endpoints } from '../api/endpoints'

interface Operation {
  parameters?: { name: string; in: string }[]
}

/** The committed OpenAPI document (Vitest runs in frontend/), which the backend serves and its tests check. */
const spec = JSON.parse(
  readFileSync(resolve(process.cwd(), '../backend/openapi.json'), 'utf8'),
) as {
  paths: Record<string, Record<string, Operation>>
}

/** Every path the app asks for, with sample ids and every query parameter it can send. */
const urls: string[] = [
  endpoints.version,
  ...Object.values(endpoints.auth),
  endpoints.account.self,
  endpoints.account.password,
  endpoints.account.blockedTags,
  endpoints.account.blockedTag(1),
  endpoints.account.passkeys,
  endpoints.account.passkeyOptions,
  endpoints.account.passkey(1),
  endpoints.lists.all,
  endpoints.lists.byId(1),
  endpoints.lists.ids(1),
  endpoints.lists.media(1),
  endpoints.lists.media(1, { limit: 60, offset: 120 }),
  endpoints.lists.item(1, 2),
  endpoints.lists.holding(2),
  endpoints.savedSearches.all,
  endpoints.savedSearches.byId(1),
  endpoints.users.all,
  endpoints.users.byId(1),
  endpoints.media.page(2, 40),
  endpoints.media.page(1, 40, 'cat,-dog'),
  endpoints.media.page(1, 40, 'untagged'),
  endpoints.media.after('abc', 40, 'cat'),
  endpoints.media.after('abc', 40, 'untagged'),
  endpoints.media.byId(1),
  endpoints.media.byIdWithTags(1),
  endpoints.media.tagImports([1, 2]),
  endpoints.media.random,
  endpoints.media.count,
  endpoints.media.byIds,
  endpoints.media.bulkDelete,
  endpoints.media.upload,
  endpoints.media.tags(1),
  endpoints.media.removeTag(1, 2),
  endpoints.media.danbooruTags(1),
  endpoints.tags.list({}),
  endpoints.tags.list({ ids: [1], names: ['cat'], exclude: [2], limit: 100 }),
  endpoints.tags.list({ search: 'ca', limit: 20 }),
  endpoints.tags.list({
    filter: 'art',
    sort: 'mediaCount',
    direction: 'desc',
    limit: 25,
    offset: 50,
    include: 'counts',
  }),
  endpoints.tags.byId(1),
  endpoints.tags.create,
  endpoints.tags.migrate(1),
  endpoints.tags.deleteMigrateTo(1, 2),
  endpoints.tagCategories.list,
  endpoints.tagCategories.byId(1),
  endpoints.tagImplications.list,
  endpoints.tagImplications.byPair(1, 2),
  endpoints.danbooru.categoryMappings,
  endpoints.danbooru.categoryMapping(0),
  endpoints.danbooru.tagMappings,
  endpoints.danbooru.tagMapping(1),
  ...Object.values(endpoints.duplicates),
]

/**
 * The spec's path a request path is, by its templates: `/media/{mediaId}` for
 * `/media/5`, and a fixed segment before a parameter (`/media/count`).
 */
function specPath(path: string): string | undefined {
  const params = (template: string) => template.split('{').length
  return Object.keys(spec.paths)
    .filter((template) => new RegExp(`^${template.replace(/\{[^}]+\}/g, '[^/]+')}$`).test(path))
    .sort((a, b) => params(a) - params(b))[0]
}

describe('endpoints', () => {
  it.each(urls)('%s is a path the API has, with query parameters it declares', (url) => {
    const { pathname, searchParams } = new URL(url, 'http://gallery.test')
    const template = specPath(pathname)
    expect(template, `${pathname} is not in openapi.json`).toBeDefined()

    const declared = new Set(
      Object.values(spec.paths[template ?? ''] ?? {}).flatMap((operation) =>
        (operation.parameters ?? []).filter((p) => p.in === 'query').map((p) => p.name),
      ),
    )
    for (const name of searchParams.keys()) {
      expect(declared, `${pathname} declares no ?${name}`).toContain(name)
    }
  })

  it('pages the media listings by offset, or after a cursor', () => {
    expect(endpoints.media.page(3, 40)).toBe('/media?limit=40&offset=80')
    expect(endpoints.media.page(1, 40, 'c++,-dog')).toBe(
      '/media?tags=c%2B%2B%2C-dog&limit=40&offset=0',
    )
    expect(endpoints.media.page(2, 15, 'untagged')).toBe('/media?untagged=true&limit=15&offset=15')
    expect(endpoints.media.after('abc', 40)).toBe('/media?limit=40&cursor=abc')
  })

  it('sends an empty search term, which asks for a ranking, but not an empty filter', () => {
    expect(endpoints.tags.list({ search: '', limit: 20 })).toBe('/tags?search=&limit=20')
    expect(endpoints.tags.list({ filter: '', exclude: [] })).toBe('/tags')
  })
})
