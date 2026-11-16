// The page-side code below runs in the browser, against its DOM.
/// <reference lib="dom" />
import type { Page } from '@playwright/test'
import { expect, test } from './fixtures.ts'

/**
 * Every colour pair the app draws, measured from the live theme in both schemes, and
 * held to WCAG 2.2 AAA: text at 7:1 (4.5:1 when large), and control edges, focus rings
 * and meaningful icons at 3:1. Each scheme is measured twice, once followed from the
 * device and once picked against it (`data-theme` on <html>), and the two must agree.
 *
 * A pair names its colours as CSS, the theme's tokens included, and what it sits on as
 * layers from the bottom up, each laid over the one below where it is see-through. A
 * new token, surface or hover state adds its pairs here.
 */

type Kind = 'text' | 'large text' | 'non-text'

interface Pair {
  name: string
  /** The foreground: any CSS colour. */
  fg: string
  /** The background, bottom layer first. */
  on: string[]
  kind?: Kind
}

/**
 * Pairs that meet only AA, each with its entry in docs/decisions.md: the pair's name, as
 * the report prints it after the scheme, and the ratio it must still reach (never below
 * AA).
 */
const exceptions = new Map<string, number>()

const v = (token: string) => `var(--${token})`
const page0 = [v('surface-0')]
const raised = [v('surface-1')]
const field = [v('surface-2')]
const pressed = [v('surface-3')]

/** The surfaces body text sits on: the page, a raised panel (navbar, card, dialog, menu, toast), a field or hover, a pressed control. */
const surfaces = { page: page0, raised, field, pressed }
type Surface = keyof typeof surfaces

const on = (fg: string, name: string, where: Surface[], kind?: Kind): Pair[] =>
  where.map((s) => ({ name: `${name} on the ${s} surface`, fg, on: surfaces[s], kind }))

const states = ['danger', 'warning', 'success', 'info'] as const
const hues = ['rose', 'amber', 'emerald', 'sky', 'violet', 'teal', 'orange', 'lime'] as const

const pairs: Pair[] = [
  // Chrome text.
  ...on(v('text-1'), 'body text', ['page', 'raised', 'field', 'pressed']),
  ...on(v('text-2'), 'secondary text', ['page', 'raised', 'field', 'pressed']),
  ...on(v('text-3'), 'muted text', ['page', 'raised', 'field']),
  ...on(v('link'), 'link', ['page', 'raised', 'field']),

  // The filled buttons and the current page's chip, at rest and hovered.
  { name: 'primary button', fg: v('surface-0'), on: [v('text-1')] },
  { name: 'primary button, hovered', fg: v('surface-0'), on: [v('text-2')] },
  { name: 'danger button', fg: v('danger-fg'), on: [v('danger')] },
  {
    name: 'danger button, hovered',
    fg: v('danger-fg'),
    on: [`color-mix(in srgb, ${v('danger')} 85%, ${v('text-1')})`],
  },
  { name: 'warning button', fg: v('warning-fg'), on: [v('warning')] },
  {
    name: 'warning button, hovered',
    fg: v('warning-fg'),
    on: [`color-mix(in srgb, ${v('warning')} 85%, ${v('text-1')})`],
  },

  // State colours as text (help, outlined buttons, menu items, chips), and on their own
  // wash, which is also an alert's background under body text.
  ...states.flatMap((state) => [
    ...on(v(state), `${state} text`, ['page', 'raised', 'field']),
    { name: `${state} text on its wash`, fg: v(state), on: [...page0, v(`${state}-soft`)] },
    {
      name: `${state} text on its wash, raised`,
      fg: v(state),
      on: [...raised, v(`${state}-soft`)],
    },
    { name: `body text on a ${state} alert`, fg: v('text-1'), on: [...page0, v(`${state}-soft`)] },
    {
      name: `body text on a ${state} alert in a dialog`,
      fg: v('text-1'),
      on: [...raised, v(`${state}-soft`)],
    },
  ]),

  // The favourite button, at rest and hovered.
  ...on(v('fav'), 'favourite', ['page', 'raised']),
  {
    name: 'favourite, hovered',
    fg: v('fav'),
    on: [...page0, `color-mix(in srgb, ${v('fav')} 12%, transparent)`],
  },

  // Tag chips, and tag names coloured by their category.
  ...hues.map((hue) => ({ name: `${hue} chip`, fg: v('tag-ink'), on: [v(`tag-${hue}`)] })),
  ...hues.flatMap((hue) =>
    on(`color-mix(in srgb, ${v(`tag-${hue}`)} 30%, ${v('text-1')})`, `${hue} tag name`, [
      'page',
      'raised',
      'field',
    ]),
  ),

  // The lightbox's caption and link, over its scrim.
  {
    name: 'lightbox caption',
    fg: 'rgb(255 255 255 / 70%)',
    on: [...page0, 'rgb(0 0 0 / 90%)'],
  },
  { name: 'lightbox link', fg: 'rgb(255 255 255 / 90%)', on: [...page0, 'rgb(0 0 0 / 90%)'] },

  // Control edges and focus rings, against what is next to them.
  ...on(v('border-strong'), 'control edge', ['page', 'raised', 'field'], 'non-text'),
  ...on(v('focus'), 'focus ring', ['page', 'raised', 'field'], 'non-text'),

  // The card's heart, on its chip over a white thumbnail (the chip's worst case).
  {
    name: 'card heart',
    fg: 'rgb(255 255 255 / 85%)',
    on: ['rgb(255 255 255)', 'rgb(0 0 0 / 65%)'],
    kind: 'non-text',
  },
  {
    name: 'card heart, favourited',
    fg: v('fav-on-scrim'),
    on: ['rgb(255 255 255)', 'rgb(0 0 0 / 65%)'],
    kind: 'non-text',
  },
]

const required: Record<Kind, number> = { text: 7, 'large text': 4.5, 'non-text': 3 }
const floor: Record<Kind, number> = { text: 4.5, 'large text': 3, 'non-text': 3 }

interface Measured {
  name: string
  ratio: number
}

/** Measures every pair in the page's current scheme. */
async function measure(page: Page): Promise<Measured[]> {
  return page.evaluate((pairs) => {
    type Rgba = [number, number, number, number]
    const probe = document.createElement('div')
    document.body.append(probe)

    // A computed colour is rgb()/rgba() with 0-255 channels, or color(srgb …) with
    // 0-1 channels for a mix.
    const parse = (value: string): Rgba => {
      const numbers = (value.match(/-?[\d.]+(?:e-?\d+)?/g) ?? []).map(Number)
      if (value.startsWith('color(srgb')) {
        const [r = 0, g = 0, b = 0, a = 1] = numbers
        return [r * 255, g * 255, b * 255, a]
      }
      const [r = 0, g = 0, b = 0, a = 1] = numbers
      return [r, g, b, a]
    }
    const resolve = (css: string): Rgba => {
      probe.style.color = ''
      probe.style.color = css
      if (probe.style.color === '') {
        throw new Error(`Not a colour: ${css}`)
      }
      return parse(getComputedStyle(probe).color)
    }
    const over = ([r, g, b, a]: Rgba, [br, bg, bb]: Rgba): Rgba => [
      r * a + br * (1 - a),
      g * a + bg * (1 - a),
      b * a + bb * (1 - a),
      1,
    ]
    const luminance = ([r, g, b]: Rgba) => {
      const lin = (c: number) => {
        const s = c / 255
        return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
      }
      return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
    }
    const contrast = (a: Rgba, b: Rgba) => {
      const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number]
      return (hi + 0.05) / (lo + 0.05)
    }

    const results = pairs.map(({ name, fg, on }) => {
      const background = on.reduce<Rgba>(
        (below, layer) => over(resolve(layer), below),
        [255, 255, 255, 1],
      )
      return { name, ratio: contrast(over(resolve(fg), background), background) }
    })
    probe.remove()
    return results
  }, pairs)
}

const schemes = ['light', 'dark'] as const
const other = { light: 'dark', dark: 'light' } as const

test('every colour pair reaches AAA in both schemes, followed or picked', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('main')).toBeVisible()

  const report: string[] = []
  const short: string[] = []
  for (const scheme of schemes) {
    // Followed from the device, with no pick on <html>.
    await page.emulateMedia({ colorScheme: scheme })
    await page.evaluate(() => {
      document.documentElement.removeAttribute('data-theme')
    })
    const followed = await measure(page)

    // Picked against the device.
    await page.emulateMedia({ colorScheme: other[scheme] })
    await page.evaluate((s) => {
      document.documentElement.setAttribute('data-theme', s)
    }, scheme)
    const picked = await measure(page)

    expect(picked, `the ${scheme} pick measures as the ${scheme} device does`).toEqual(followed)

    for (const [i, { name, ratio }] of followed.entries()) {
      const kind = pairs[i]?.kind ?? 'text'
      const exception = exceptions.get(name)
      const needs = exception === undefined ? required[kind] : Math.max(exception, floor[kind])
      const level = ratio >= required[kind] ? 'AAA' : ratio >= floor[kind] ? 'AA' : 'fails AA'
      const line = `${scheme}: ${name} ${ratio.toFixed(2)}:1 (${kind}, ${level})`
      report.push(line)
      if (ratio < needs) {
        short.push(line)
      }
    }
  }
  await test.info().attach('contrast', { body: report.join('\n'), contentType: 'text/plain' })
  expect(short, `Below the level each pair must reach. Every pair:\n${report.join('\n')}`).toEqual(
    [],
  )
})
