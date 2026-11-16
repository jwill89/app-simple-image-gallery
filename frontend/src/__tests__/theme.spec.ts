import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const root = document.documentElement

/** A fresh copy of the module, which applies the stored pick as it loads. */
async function loadTheme() {
  vi.resetModules()
  return import('../theme')
}

describe('theme', () => {
  beforeEach(() => {
    localStorage.clear()
    root.removeAttribute('data-theme')
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('follows the device when nothing is stored', async () => {
    const { useTheme } = await loadTheme()

    expect(useTheme().theme.value).toBe('system')
    expect(root.hasAttribute('data-theme')).toBe(false)
  })

  it('applies a stored pick as the module loads', async () => {
    localStorage.setItem('theme', 'dark')

    const { useTheme } = await loadTheme()

    expect(useTheme().theme.value).toBe('dark')
    expect(root.getAttribute('data-theme')).toBe('dark')
  })

  it('ignores a stored value that is not a pick', async () => {
    localStorage.setItem('theme', 'sepia')

    const { storedTheme } = await loadTheme()

    expect(storedTheme()).toBe('system')
    expect(root.hasAttribute('data-theme')).toBe(false)
  })

  it('follows the device when storage cannot be read', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })

    const { storedTheme } = await loadTheme()

    expect(storedTheme()).toBe('system')
  })

  it('puts light or dark on <html>, and takes it off for the device', async () => {
    const { applyTheme } = await loadTheme()

    applyTheme('light')
    expect(root.getAttribute('data-theme')).toBe('light')
    applyTheme('dark')
    expect(root.getAttribute('data-theme')).toBe('dark')
    applyTheme('system')
    expect(root.hasAttribute('data-theme')).toBe(false)
  })

  it('applies and keeps a pick, and forgets it for the device', async () => {
    const { useTheme } = await loadTheme()
    const { theme, setTheme } = useTheme()

    setTheme('light')
    expect(theme.value).toBe('light')
    expect(root.getAttribute('data-theme')).toBe('light')
    expect(localStorage.getItem('theme')).toBe('light')

    setTheme('system')
    expect(theme.value).toBe('system')
    expect(root.hasAttribute('data-theme')).toBe(false)
    expect(localStorage.getItem('theme')).toBeNull()
  })

  it('still applies a pick when storage cannot be written', async () => {
    const { useTheme } = await loadTheme()
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError')
    })

    useTheme().setTheme('dark')

    expect(root.getAttribute('data-theme')).toBe('dark')
    expect(useTheme().theme.value).toBe('dark')
  })

  it('tells a pick from anything else', async () => {
    const { isTheme } = await loadTheme()

    expect(['system', 'light', 'dark'].every(isTheme)).toBe(true)
    expect(isTheme('auto')).toBe(false)
    expect(isTheme(null)).toBe(false)
  })
})
