// Stylelint checks the styles for mistakes and holds the palette rule; it doesn't
// format them (Prettier does). The standard CSS rules, in the .vue files' style
// blocks as well. A rule is turned off here with its reason, or at the one line
// it doesn't fit with the reason beside it.

// AGENTS.md §8: a colour is defined once, as a token in style.css's :root blocks,
// and used through var(). Neutral white and black at some alpha (scrims,
// hairlines, text on a dark chip) are not palette colours and may be written
// inline.
const PALETTE_MESSAGE =
  "Use a token from style.css's :root blocks (var(--…), color-mix() for a tint); only neutral white/black may be written inline"

export default {
  extends: ['stylelint-config-standard', 'stylelint-config-recommended-vue'],
  rules: {
    'color-no-hex': [true, { message: PALETTE_MESSAGE }],
    'declaration-property-value-disallowed-list': [
      {
        '/.*/': [
          // rgb()/rgba()/hsl()/hsla() unless the channels are pure white or black.
          '/\\b(?:rgba?|hsla?)\\((?!\\s*(?:255[\\s,]+255[\\s,]+255|0[\\s,]+0[\\s,]+0)[\\s,/])/',
        ],
      },
      { message: PALETTE_MESSAGE },
    ],
    // Tag colours are modifiers: `tag--rose`, `tag-text--rose`.
    'selector-class-pattern': [
      '^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z0-9]+(-[a-z0-9]+)*)?(--[a-z0-9]+(-[a-z0-9]+)*)?$',
      { message: 'Name classes in kebab-case, with a --modifier where one applies' },
    ],
    // A typeface's name is written as the foundry writes it.
    'value-keyword-case': ['lower', { ignoreProperties: ['/^--font-/'] }],
    // The tokens sit in groups, a blank line between one group and the next.
    'custom-property-empty-line-before': null,
    // It compares only the last compound selector, so it can't tell `.footer a`
    // from `.breadcrumb a`, or that `:hover:not(:disabled)` and `:disabled`
    // never both apply: all five of its findings here were selectors that set
    // different properties or can't match the same element at once.
    'no-descending-specificity': null,
  },
}
