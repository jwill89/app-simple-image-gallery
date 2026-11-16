// Stylelint checks the styles for mistakes and holds the palette rule; it doesn't
// format them (Prettier does). The shared CSS rules, in the .vue files' style blocks
// as well. A rule is turned off here with its reason, or at the one line it doesn't
// fit with the reason beside it.
import { paletteRules } from '@jwill89/lint-web/stylelint'

// A colour is defined once, as a token in src/tokens.css, and used through var().
// Neutral white and black at some alpha (scrims, hairlines, text on a dark chip) are
// not palette colours and may be written inline, as rgb(255 255 255) and
// rgb(0 0 0 / a), never by name.
const PALETTE_MESSAGE =
  'Use a token from src/tokens.css (var(--…), color-mix() for a tint); only neutral white/black may be written inline, as rgb()'

const palette = paletteRules(PALETTE_MESSAGE)

export default {
  extends: ['@jwill89/lint-web/stylelint'],
  rules: {
    ...palette,
    // It compares only the last compound selector, so it can't tell `.footer a`
    // from `.breadcrumb a`, or that `:hover:not(:disabled)` and `:disabled`
    // never both apply: what it finds here is selectors that set different
    // properties or can't match the same element at once.
    'no-descending-specificity': null,
  },
  overrides: [
    {
      // The token file is where the palette's colours are written.
      files: ['src/tokens.css'],
      rules: Object.fromEntries(Object.keys(palette).map((rule) => [rule, null])),
    },
  ],
}
