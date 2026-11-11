/**
 * Tag category display utilities.
 *
 * Categories are fetched from the API and stored in the gallery store.
 * This module maps a category's stored `color` to the CSS classes defined
 * in `style.css`.
 *
 * These colours are **display only**. They deliberately do not overlap with
 * the semantic palette (danger / success / warning / info), which is reserved
 * for state. A category stored as a Bulma semantic name (Artist as `danger`)
 * would draw the Artist chip the same red as the delete button, muddying the
 * meaning of colour and tying the tag palette to Bulma. The `tag--<hue>`
 * namespace below is ours and does not depend on Bulma.
 *
 * Keep `VALID_COLORS` in sync with `TagCategory::COLORS` in the backend.
 */

import { useGalleryStore, type TagCategory } from '../stores/gallery'

/**
 * Colours a tag category may use. Hue names only — nothing here may be a
 * state name, or a category chip would read as a state.
 */
export const VALID_COLORS = [
  'neutral',
  'rose',
  'amber',
  'emerald',
  'sky',
  'violet',
  'teal',
  'orange',
  'lime',
] as const

type ValidColor = (typeof VALID_COLORS)[number]

const DEFAULT_COLOR: ValidColor = 'neutral'

function normalise(color: string | null | undefined): ValidColor {
  return VALID_COLORS.includes(color as ValidColor) ? (color as ValidColor) : DEFAULT_COLOR
}

// ── Color → CSS class derivations ───────────────────────────

export function colorToTagClass(color: string | null | undefined): string {
  return `tag--${normalise(color)}`
}

export function colorToTextClass(color: string | null | undefined): string {
  return `tag-text--${normalise(color)}`
}

// ── Lookup helpers (use store data) ─────────────────────────

function findCategory(predicate: (c: TagCategory) => boolean): TagCategory | undefined {
  const store = useGalleryStore()
  return store.categories.find(predicate)
}

/**
 * Get the tag class for a given category ID.
 */
export function getCategoryClassById(categoryId: number): string {
  const cat = findCategory((c) => c.category_id === categoryId)
  return colorToTagClass(cat?.color)
}

/** The name of the category with this ID, or '' when there is none. */
export function getCategoryNameById(categoryId: number): string {
  return findCategory((c) => c.category_id === categoryId)?.category_name ?? ''
}

/**
 * Get the text class for a given category ID.
 */
export function getTextClassById(categoryId: number): string {
  const cat = findCategory((c) => c.category_id === categoryId)
  return colorToTextClass(cat?.color)
}

/**
 * Get the tag class for a given category name. A null/unknown name yields the
 * default class (category_name can be null on tags whose category was removed).
 */
export function getCategoryClassByName(categoryName: string | null | undefined): string {
  const cat = findCategory((c) => c.category_name === categoryName)
  return colorToTagClass(cat?.color)
}
