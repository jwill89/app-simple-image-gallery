import { describe, expect, it } from 'vitest'
import {
  findIconDefinition,
  type IconDefinition,
  type IconName,
  type IconPrefix,
} from '@fortawesome/fontawesome-svg-core'
import '../fontawesome'

// Every icon the source names, as the "fa-solid fa-<name>" strings <font-awesome-icon>
// takes, must be registered in src/fontawesome.ts: an unregistered one renders nothing.

const PREFIX: Record<string, IconPrefix> = {
  'fa-solid': 'fas',
  'fa-regular': 'far',
  'fa-brands': 'fab',
}

// The source as text, these specs left out.
const files = Object.entries(
  import.meta.glob<string>(['../**/*.{vue,ts}', '!./**'], {
    query: '?raw',
    import: 'default',
    eager: true,
  }),
).map(([path, text]) => ({ name: path.replace(/^\.\.\//, ''), text }))

describe('icons', () => {
  it('reads the source', () => {
    expect(files.length).toBeGreaterThan(20)
  })

  it('registers every icon the source names', () => {
    const missing: string[] = []
    for (const { name, text } of files) {
      for (const [whole, style = '', icon = ''] of text.matchAll(
        /\b(fa-solid|fa-regular|fa-brands) fa-([a-z0-9-]+)/g,
      )) {
        // Typed as always found, but it answers undefined for an unregistered icon.
        const found = findIconDefinition({
          prefix: PREFIX[style] ?? 'fas',
          iconName: icon as IconName,
        }) as IconDefinition | undefined
        if (!found) {
          missing.push(`${name}: ${whole}`)
        }
      }
    }
    expect(missing).toEqual([])
  })

  it('renders icons with <font-awesome-icon>, never an <i> with icon classes', () => {
    const elements = files.flatMap(({ name, text }) =>
      [...text.matchAll(/<i\b[^>]*\bfa-[a-z]/g)].map((m) => `${name}: ${m[0]}`),
    )
    expect(elements).toEqual([])
  })
})
