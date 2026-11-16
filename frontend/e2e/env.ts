import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

/**
 * Where the end-to-end run serves the Gallery as production does: the Laravel app behind
 * PHP's built-in server, answering every page through its own routing (SpaController,
 * with the built index.html and its Content-Security-Policy) and serving the build's
 * files from a public folder of its own, on a throwaway database holding the backend's
 * contract fixture (30 media, 16 tags).
 */
export const SITE_PORT = 4181
export const SITE_URL = `http://127.0.0.1:${String(SITE_PORT)}`

export const FRONTEND_DIR = resolve(import.meta.dirname, '..')
export const BACKEND_DIR = resolve(FRONTEND_DIR, '../backend')
export const DATA_DIR = join(tmpdir(), 'gallery-e2e')
export const DATABASE = join(DATA_DIR, 'gallery.db')

/** The build (`vite build`), whose files the server's public folder gets. */
export const DIST_DIR = join(FRONTEND_DIR, 'dist')
/** The server's document root: the build's files, and a front controller for the rest. */
export const PUBLIC_DIR = join(DATA_DIR, 'public')
/** Where the app reads the built index.html from (config gallery.spa_index), as on the droplet. */
export const SPA_INDEX = join(BACKEND_DIR, 'resources/spa/index.html')
/** Laravel's router for PHP's built-in server: a real file as it is, anything else to the app. */
export const SERVER_ROUTER = join(
  BACKEND_DIR,
  'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
)

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
  SESSION_DRIVER: 'database',
  GALLERY_ALLOW_SIGNUP: 'true',
  GALLERY_ALLOWED_ORIGINS: SITE_URL,
  GALLERY_URL: SITE_URL,
  GALLERY_MEDIA_PATH: join(DATA_DIR, 'media'),
  GALLERY_DUPES_PATH: join(DATA_DIR, 'dupes'),
  // The policy enforced, whatever backend/.env says.
  GALLERY_CSP_REPORT_ONLY: 'false',
  // Turnstile off, as in local development, and no Danbooru account.
  GALLERY_TURNSTILE_SITEKEY: '',
  GALLERY_TURNSTILE_SECRET: '',
  GALLERY_DANBOORU_LOGIN: '',
  GALLERY_DANBOORU_API_KEY: '',
}
