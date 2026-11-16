import { defineConfig } from '@playwright/test'
import { playwrightDefaults } from '../shared/test/e2e/playwright.js'
import {
  DATA_DIR,
  FRONTEND_DIR,
  PUBLIC_DIR,
  SERVER_ROUTER,
  SITE_PORT,
  SITE_URL,
  backendEnv,
} from './e2e/env.ts'

// The end-to-end run (`npm run test:e2e`), on the shared Playwright settings: the built
// SPA served as production serves it, by the Laravel app on PHP's built-in server. Every
// page comes from the app's own routing, with its real Content-Security-Policy; the
// build's files are served as files; and the API runs on a throwaway database holding
// the backend's contract fixture (e2e/server/prepare.ts). Locally on Windows it drives
// the installed Edge.
//
// Playwright refuses to start when something already answers on the port, and the
// server's request log goes to a file in the run's data folder.
const cd = process.platform === 'win32' ? 'cd /d' : 'cd'

export default defineConfig({
  ...playwrightDefaults,
  use: {
    ...playwrightDefaults.use,
    baseURL: SITE_URL,
    // The production build registers a service worker that caches thumbnails; the run
    // checks the pages, not the worker.
    serviceWorkers: 'block',
  },
  webServer: {
    // Laravel's router for PHP's server, run from the public folder as `artisan serve`
    // runs it, but given this run's environment as it is.
    command: [
      'npx vite build --logLevel warn',
      'node e2e/server/prepare.ts',
      `${cd} "${PUBLIC_DIR}"`,
      `php -S 127.0.0.1:${String(SITE_PORT)} "${SERVER_ROUTER}" 2> "${DATA_DIR}/server.log"`,
    ].join(' && '),
    cwd: FRONTEND_DIR,
    url: `${SITE_URL}/api/version`,
    env: process.platform === 'win32' ? backendEnv : { ...backendEnv, PHP_CLI_SERVER_WORKERS: '4' },
    reuseExistingServer: false,
    timeout: 240_000,
  },
})
