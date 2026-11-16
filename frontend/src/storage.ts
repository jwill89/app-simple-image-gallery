/**
 * This browser's preferences, in localStorage. A private window or blocked site data
 * can make storage throw on any read or write, so every call goes through here: a
 * read that fails is nothing stored, and a write that fails is dropped, leaving the
 * preference for this page only.
 */

/** The stored value, or null when there is none or storage is out of reach. */
export function readPreference(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

/** Keeps a value, or forgets the key for null. */
export function writePreference(key: string, value: string | null): void {
  try {
    if (value === null) {
      localStorage.removeItem(key)
    } else {
      localStorage.setItem(key, value)
    }
  } catch {
    // Storage is blocked or full: the preference holds until the page is left.
  }
}
