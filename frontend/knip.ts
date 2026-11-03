import type { KnipConfig } from 'knip'

// Knip finds unused files, exports and dependencies (`npm run knip`, run in CI).
// Its Vite, Vitest and ESLint plugins find the entry points; these are the cases
// it can't see by itself.
const config: KnipConfig = {
  // The service worker: built to /sw.js by vite.config.ts and registered by URL
  // in main.ts, never imported.
  entry: ['src/sw/sw.ts'],
  ignore: [
    // Generated from backend/openapi.json; its unused exports are the generator's.
    'src/types/api.generated.ts',
    // The shared ESLint config is a git submodule with its own package.json.
    'eslint-config/**',
  ],
}

export default config
