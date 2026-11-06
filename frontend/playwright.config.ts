import { defineConfig } from '@playwright/test'
import { playwrightDefaults } from '../shared/test/e2e/playwright.js'
import {
  BACKEND_DIR,
  BACKEND_PORT,
  BACKEND_URL,
  DATA_DIR,
  SITE_PORT,
  SITE_URL,
  backendEnv,
} from './e2e/env.ts'

// The end-to-end run (`npm run test:e2e`), on shared-tooling's Playwright settings: the
// built SPA under `vite preview`, which sends /api and the media files to the Laravel app
// on PHP's built-in server, on a throwaway database holding the backend's contract
// fixture (e2e/server/prepare.ts). Locally on Windows it drives the installed Edge.
export default defineConfig({
  ...playwrightDefaults,
  use: {
    ...playwrightDefaults.use,
    baseURL: SITE_URL,
    // The production build registers a service worker that caches thumbnails; the run
    // checks the pages, not the worker.
    serviceWorkers: 'block',
  },
  webServer: [
    {
      // Laravel's own router for PHP's server, run from public/ as `artisan serve` runs
      // it, but given this run's environment as it is. Its request log goes to a file.
      command: `node ../frontend/e2e/server/prepare.ts && cd public && php -S 127.0.0.1:${String(BACKEND_PORT)} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php 2> "${DATA_DIR}/server.log"`,
      cwd: BACKEND_DIR,
      url: `${BACKEND_URL}/api/version`,
      env:
        process.platform === 'win32' ? backendEnv : { ...backendEnv, PHP_CLI_SERVER_WORKERS: '4' },
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: `npx vite build --logLevel warn && npx vite preview --host 127.0.0.1 --port ${String(SITE_PORT)} --strictPort`,
      url: SITE_URL,
      env: { GALLERY_BACKEND_URL: BACKEND_URL },
      reuseExistingServer: false,
      timeout: 180_000,
    },
  ],
})
