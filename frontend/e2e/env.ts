import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

/**
 * Where the end-to-end run serves the Gallery: the built SPA from `vite preview`, which
 * sends /api and the media files to the Laravel app behind PHP's built-in server, on a
 * throwaway database holding the backend's contract fixture (30 media, 16 tags).
 */
export const BACKEND_PORT = 8181
export const SITE_PORT = 4181
export const BACKEND_URL = `http://127.0.0.1:${String(BACKEND_PORT)}`
export const SITE_URL = `http://127.0.0.1:${String(SITE_PORT)}`

export const BACKEND_DIR = resolve(import.meta.dirname, '../../backend')
export const DATA_DIR = join(tmpdir(), 'gallery-e2e')
export const DATABASE = join(DATA_DIR, 'gallery.db')

/** One of the fixture's thumbnails, served for every thumbnail the pages ask for. */
export const THUMBNAIL = join(BACKEND_DIR, 'tests/Fixtures/media/thumbs/dupe-a1@2x.webp')

/**
 * What the Laravel app runs with, on top of backend/.env (real environment variables win
 * over it). APP_ENV names no .env file, so backend/.env is the one read.
 */
export const backendEnv: Record<string, string> = {
  APP_ENV: 'e2e',
  APP_DEBUG: 'false',
  APP_URL: SITE_URL,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: DATABASE,
  // The rate limiters count in the cache. An array store forgets between requests, so
  // sign-ups from earlier runs can't trip the limit of five an hour.
  CACHE_STORE: 'array',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  GALLERY_ALLOW_SIGNUP: 'true',
  GALLERY_ALLOWED_ORIGINS: SITE_URL,
  GALLERY_URL: SITE_URL,
  GALLERY_MEDIA_PATH: join(DATA_DIR, 'media'),
  GALLERY_DUPES_PATH: join(DATA_DIR, 'dupes'),
  // Turnstile off, as in local development, and no Danbooru account.
  CLOUDFLARE_TURNSTILE_SITEKEY: '',
  CLOUDFLARE_TURNSTILE_SECRET: '',
  DANBOORU_LOGIN: '',
  DANBOORU_API_KEY: '',
}
