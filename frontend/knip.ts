import type { KnipConfig } from 'knip'

// Knip finds unused files, exports and dependencies (`npm run knip`, run in CI).
// Its Vite, Vitest and ESLint plugins find the entry points; these are the cases
// it can't see by itself.
const config: KnipConfig = {
  // The service worker: built to /sw.js by vite.config.ts and registered by URL
  // in main.ts, never imported. The end-to-end run's prepare script: run by
  // playwright.config.ts's server command.
  entry: ['src/sw/sw.ts', 'e2e/server/prepare.ts'],
  // The end-to-end run's server is PHP, the backend's, never an npm package.
  ignoreBinaries: ['php'],
  ignore: [
    // Generated from backend/openapi.json; its unused exports are the generator's.
    'src/types/api.generated.ts',
  ],
}

export default config
