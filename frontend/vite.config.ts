import { defineConfig } from 'vitest/config'
import { build, type Plugin, type ResolvedConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, resolve } from 'node:path'
import { vitestDefaults } from '../shared/test/web/vitest.js'

const require = createRequire(import.meta.url)

// Read the app version from package.json so the footer can display it without a
// runtime request. createRequire avoids JSON import-assertion syntax churn.
const pkg = require('./package.json') as { version: string }

/**
 * Builds the service worker (src/sw/sw.ts) into dist/sw.js once the app is
 * built: one classic script at the site root under a fixed name, since the
 * browser registers the worker by that URL and updates it by comparing its
 * bytes. Built with the app, it would get a hashed name and import the code it
 * shares with the app from a separate chunk.
 */
function serviceWorker(): Plugin {
  let config: ResolvedConfig
  return {
    name: 'gallery-service-worker',
    apply: 'build',
    configResolved(resolved) {
      config = resolved
    },
    async closeBundle() {
      await build({
        configFile: false,
        root: config.root,
        logLevel: 'warn',
        build: {
          outDir: resolve(config.root, config.build.outDir),
          emptyOutDir: false,
          copyPublicDir: false,
          lib: {
            entry: resolve(config.root, 'src/sw/sw.ts'),
            formats: ['iife'],
            name: 'gallerySw',
            fileName: () => 'sw.js',
          },
        },
      })
    },
  }
}

/**
 * The API reference at /api/docs (the backend's SystemController::docs()): Swagger UI's
 * browser bundle, its licence notices and its stylesheet, copied from swagger-ui-dist
 * into dist/api-docs/, beside the script that points it at the API's description. The
 * deploy puts them in the backend's public/, so the reference runs from the gallery's
 * own origin under a Content-Security-Policy that admits nothing else.
 */
function apiReference(): Plugin {
  const folder = dirname(require.resolve('swagger-ui-dist/package.json'))
  const files = ['swagger-ui-bundle.js', 'swagger-ui-bundle.js.LICENSE.txt', 'swagger-ui.css']
  return {
    name: 'gallery-api-reference',
    apply: 'build',
    generateBundle() {
      for (const file of files) {
        this.emitFile({
          type: 'asset',
          fileName: `api-docs/${file}`,
          source: readFileSync(resolve(folder, file)),
        })
      }
      this.emitFile({
        type: 'asset',
        fileName: 'api-docs/reference.js',
        source:
          "SwaggerUIBundle({ url: '/api/openapi.json', dom_id: '#reference', deepLinking: true })\n",
      })
    },
  }
}

// The Laravel app the dev server (and `vite preview`) sends the API and the media
// files to: http://localhost, or GALLERY_BACKEND_URL (`php artisan serve`'s
// http://127.0.0.1:8000, say).
const backend = process.env.GALLERY_BACKEND_URL ?? 'http://localhost'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), serviceWorker(), apiReference()],
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
  },
  server: {
    // Only the media files, not all of /media: the SPA's own pages live there too
    // (/media/1/40, /media/5/tags), and a reload of one must get the SPA.
    proxy: {
      '/api': { target: backend, changeOrigin: true },
      '/media/full': { target: backend, changeOrigin: true },
      '/media/thumbs': { target: backend, changeOrigin: true },
    },
  },
  // Every <script> and <link> the build writes into index.html carries this nonce, and
  // so does a <meta property="csp-nonce"> that Vite's preloads (and useTurnstile) read
  // for the tags they add. The backend's SpaController puts a fresh nonce in its
  // place in every answer, and names it in the page's Content-Security-Policy.
  html: {
    cspNonce: '__CSP_NONCE__',
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  // The shared test settings: jsdom, mocks and stubbed globals put back after each
  // test, and v8 coverage over all of src. `npm run test:coverage` fails below the
  // floor, which sits a little under what the specs reach. Raise it as specs land;
  // never lower it.
  test: vitestDefaults({
    environment: 'jsdom',
    include: ['src/**/*.spec.ts'],
    setupFiles: ['./vitest.setup.ts'],
    floor: { statements: 20, branches: 14.5, functions: 18, lines: 19.5 },
  }),
})
