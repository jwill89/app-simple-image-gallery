import shared from '@jwill89/eslint-config'
import vueA11y from 'eslint-plugin-vuejs-accessibility'

// Shared ruleset (Vue 3 + TypeScript strictTypeChecked + Prettier) lives in the
// @jwill89/eslint-config submodule (frontend/eslint-config). Project-specific
// ignores and rules live here. Build/tooling config files are not linted.
export default [
  {
    name: 'gallery/ignores',
    ignores: [
      'dist/**',
      'coverage/**',
      'public/**',
      'eslint-config/**', // the shared-config submodule itself
      'src/types/api.generated.ts', // generated from the OpenAPI spec
      '*.config.ts',
      '*.config.js',
      'knip.ts',
      'vitest.setup.ts',
    ],
  },
  ...shared,
  // Template accessibility: labels, alt text, interactive roles.
  ...vueA11y.configs['flat/recommended'],
  {
    name: 'gallery/a11y',
    rules: {
      // A label works by wrapping its control or by `for`/`id`; the plugin's
      // default demands both.
      'vuejs-accessibility/label-has-for': [
        'error',
        { required: { some: ['nesting', 'id'] }, allowChildren: true },
      ],
    },
  },
  {
    // The parts of the Vue style guide the shared config leaves off, holding the
    // conventions in AGENTS.md §8: <script setup lang="ts"> only, no inline styles.
    name: 'gallery/vue',
    files: ['**/*.vue'],
    rules: {
      'vue/block-lang': ['error', { script: { lang: 'ts' } }],
      'vue/component-api-style': ['error', ['script-setup']],
      'vue/no-static-inline-styles': 'error',
      'vue/define-macros-order': 'error',
      'vue/require-typed-ref': 'error',
      'vue/prefer-use-template-ref': 'error',
      'vue/no-required-prop-with-default': 'error',
      'vue/no-undef-components': ['error', { ignorePatterns: ['^Router(Link|View)$'] }],
      'vue/no-unused-refs': 'error',
      'vue/no-useless-v-bind': 'error',
      'vue/no-useless-mustaches': 'error',
      'vue/prefer-true-attribute-shorthand': 'error',
      'vue/html-button-has-type': 'error',
    },
  },
  {
    // typescript-eslint's stylistic-type-checked set, on top of the shared
    // strictTypeChecked. Named here rather than spread from typescript-eslint:
    // the shared config registers the plugin from the submodule's own install,
    // and a second copy from this package's node_modules can't be registered
    // under the same name.
    name: 'gallery/ts-stylistic',
    files: ['**/*.{ts,vue}'],
    rules: {
      '@typescript-eslint/adjacent-overload-signatures': 'error',
      '@typescript-eslint/array-type': 'error',
      '@typescript-eslint/ban-tslint-comment': 'error',
      '@typescript-eslint/class-literal-property-style': 'error',
      '@typescript-eslint/consistent-generic-constructors': 'error',
      '@typescript-eslint/consistent-indexed-object-style': 'error',
      '@typescript-eslint/consistent-type-assertions': 'error',
      '@typescript-eslint/consistent-type-definitions': 'error',
      'dot-notation': 'off',
      '@typescript-eslint/dot-notation': 'error',
      '@typescript-eslint/no-confusing-non-null-assertion': 'error',
      '@typescript-eslint/no-empty-function': 'error',
      '@typescript-eslint/no-inferrable-types': 'error',
      '@typescript-eslint/non-nullable-type-assertion-style': 'error',
      '@typescript-eslint/prefer-find': 'error',
      '@typescript-eslint/prefer-for-of': 'error',
      '@typescript-eslint/prefer-function-type': 'error',
      '@typescript-eslint/prefer-includes': 'error',
      // An empty string or false falls back as nothing would, so || stays where
      // it is meant.
      '@typescript-eslint/prefer-nullish-coalescing': [
        'error',
        { ignorePrimitives: { string: true, boolean: true } },
      ],
      '@typescript-eslint/prefer-optional-chain': 'error',
      '@typescript-eslint/prefer-regexp-exec': 'error',
      '@typescript-eslint/prefer-string-starts-ends-with': 'error',
    },
  },
  {
    // Every API call goes through composables/useApi.ts, so a failure always
    // arrives as a structured ApiError, the bearer token is always attached and
    // a 401 always clears it (AGENTS.md §8).
    name: 'gallery/api-client-only',
    files: ['src/**/*.{ts,vue}'],
    ignores: ['src/composables/useApi.ts', 'src/__tests__/**'],
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
