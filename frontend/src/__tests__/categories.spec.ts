import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import {
  VALID_COLORS,
  colorToTagClass,
  colorToTextClass,
  getCategoryClassById,
  getCategoryClassByName,
  getCategoryNameById,
  getTextClassById,
} from '../constants/categories'
import { useGalleryStore, type TagCategory } from '../stores/gallery'

const category = (over: Partial<TagCategory> = {}): TagCategory => ({
  category_id: 1,
  category_name: 'Character',
  color: 'teal',
  description: '',
  sort_order: 0,
  ...over,
})

describe('categories helpers', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('builds tag/text classes from a color', () => {
    expect(colorToTagClass('teal')).toBe('tag--teal')
    expect(colorToTextClass('rose')).toBe('tag-text--rose')
  })

  it('falls back to neutral for empty colors', () => {
    expect(colorToTagClass('')).toBe('tag--neutral')
    expect(colorToTextClass('')).toBe('tag-text--neutral')
  })

  it('falls back to neutral for colors outside the palette', () => {
    expect(colorToTagClass(null)).toBe('tag--neutral')
    expect(colorToTagClass(undefined)).toBe('tag--neutral')
    // A row that holds a semantic state name must not emit a class that would
    // collide with the semantic palette.
    expect(colorToTagClass('danger')).toBe('tag--neutral')
    expect(colorToTextClass('warning')).toBe('tag-text--neutral')
  })

  it('keeps the tag palette free of semantic state names', () => {
    for (const state of ['primary', 'link', 'info', 'success', 'warning', 'danger']) {
      expect(VALID_COLORS).not.toContain(state)
    }
    expect(VALID_COLORS).toContain('neutral')
    expect(VALID_COLORS).toContain('emerald')
  })

  it('looks up a category class, text class and name from the store', () => {
    const store = useGalleryStore()
    store.categories = [category({ category_id: 1, category_name: 'Character', color: 'teal' })]

    expect(getCategoryClassById(1)).toBe('tag--teal')
    expect(getCategoryClassByName('Character')).toBe('tag--teal')
    expect(getTextClassById(1)).toBe('tag-text--teal')
    expect(getCategoryNameById(1)).toBe('Character')
  })

  it('returns neutral defaults for unknown categories', () => {
    useGalleryStore()

    expect(getCategoryClassById(999)).toBe('tag--neutral')
    expect(getCategoryClassByName('Nope')).toBe('tag--neutral')
    expect(getTextClassById(999)).toBe('tag-text--neutral')
    expect(getCategoryNameById(999)).toBe('')
  })
})
