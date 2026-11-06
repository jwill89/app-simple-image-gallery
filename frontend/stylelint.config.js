// Stylelint checks the styles for mistakes and holds the palette rule; it doesn't
// format them (Prettier does). The shared CSS rules (shared-tooling's lint/web), in
// the .vue files' style blocks as well. A rule is turned off here with its reason, or
// at the one line it doesn't fit with the reason beside it.
import { paletteRules } from '@jwill89/lint-web/stylelint'

// AGENTS.md §8: a colour is defined once, as a token in style.css's :root blocks,
// and used through var(). Neutral white and black at some alpha (scrims,
// hairlines, text on a dark chip) are not palette colours and may be written
// inline.
const PALETTE_MESSAGE =
  "Use a token from style.css's :root blocks (var(--…), color-mix() for a tint); only neutral white/black may be written inline"

export default {
  extends: ['@jwill89/lint-web/stylelint'],
  rules: {
    ...paletteRules(PALETTE_MESSAGE),
    // The shared palette rule refuses a colour's name anywhere, but here a token is
    // darkened with neutral black by name (color-mix(…, black)), which the palette
    // allows. The hex and rgb() rules above still hold every other colour.
    'color-named': null,
    // It compares only the last compound selector, so it can't tell `.footer a`
    // from `.breadcrumb a`, or that `:hover:not(:disabled)` and `:disabled`
    // never both apply: all five of its findings here were selectors that set
    // different properties or can't match the same element at once.
    'no-descending-specificity': null,
  },
}
