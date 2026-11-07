import shared, { tsStylistic, vueConventions } from '@jwill89/lint-web'

// The shared front-end rules (shared-tooling's lint/web): Vue 3 + TypeScript
// strictTypeChecked, template accessibility, the Vue conventions AGENTS.md §8 holds
// (<script setup lang="ts"> only, no inline styles) and typescript-eslint's stylistic
// set, with Prettier's conflict list. Below them, what only this app needs. Build and
// tooling config files are not linted.
export default [
  {
    name: 'gallery/ignores',
    ignores: [
      'dist/**',
      'coverage/**',
      'public/**',
      'src/types/api.generated.ts', // generated from the OpenAPI spec
      '*.config.ts',
      '*.config.js',
      'knip.ts',
      'vitest.setup.ts',
    ],
  },
  ...shared,
  vueConventions,
  tsStylistic,
  {
    name: 'gallery/a11y',
    rules: {
      // A label works by wrapping its control or by `for`/`id`, and a label whose
      // control is a child component counts.
      'vuejs-accessibility/label-has-for': [
        'error',
        { required: { some: ['nesting', 'id'] }, allowChildren: true },
      ],
    },
  },
  {
    // Every API call goes through composables/useApi.ts, so a failure always
    // arrives as a structured ApiError, the bearer token is always attached and
    // a 401 always clears it (AGENTS.md §8).
    name: 'gallery/api-client-only',
    files: ['src/**/*.{ts,vue}'],
    // The service worker downloads thumbnails and assets, not the API.
    ignores: ['src/composables/useApi.ts', 'src/__tests__/**', 'src/sw/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: "CallExpression[callee.name='fetch']",
          message: 'Call the API through useApi (composables/useApi.ts).',
        },
        {
          selector:
            "MemberExpression[object.name=/^(window|globalThis|self)$/][property.name='fetch']",
          message: 'Call the API through useApi (composables/useApi.ts).',
        },
      ],
    },
  },
]
