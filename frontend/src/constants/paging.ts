/** The most items one page of a listing may hold (the API's `limit`). */
export const MAX_PAGE_SIZE = 100

/** Items per page when a route gives none. */
const DEFAULT_PAGE_SIZE = 40

/**
 * A media route's items per page: the default when the URL gives none (or `0`,
 * the navbar's sentinel for infinite scroll), and never more than the API takes.
 */
export function pageSize(perPage: number): number {
  return isNaN(perPage) || perPage < 1
    ? DEFAULT_PAGE_SIZE
    : Math.min(Math.floor(perPage), MAX_PAGE_SIZE)
}
