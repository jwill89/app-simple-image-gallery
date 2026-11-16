const API_BASE_URL = '/api'

/** What every problem type the API sends starts with; the failure's code follows it. */
const PROBLEM_TYPE = 'tag:gallery.mathdad.me,2026:'

/**
 * Custom error class that carries structured API error info.
 */
export class ApiError extends Error {
  /** HTTP status code */
  status: number
  /** The problem's machine-readable code (e.g. 'MediaNotFound') */
  code: string
  /** A failed validation's messages, by the field they are about (e.g. `tagName`). */
  fields: Partial<Record<string, string[]>>

  constructor(
    status: number,
    code: string,
    message: string,
    fields: Partial<Record<string, string[]>> = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
  }
}

/**
 * Extract a human-readable message from an unknown thrown value. `Error`
 * (and `ApiError`) carry a `.message`; anything else falls back to `fallback`.
 */
export function getErrorMessage(err: unknown, fallback = 'Something went wrong'): string {
  return err instanceof Error && err.message ? err.message : fallback
}

/** The HTTP status of a thrown ApiError, or 0 for any other value. */
export function getErrorStatus(err: unknown): number {
  return err instanceof ApiError ? err.status : 0
}

/** The machine-readable code of a thrown ApiError, or '' for any other value. */
export function getErrorCode(err: unknown): string {
  return err instanceof ApiError ? err.code : ''
}

/**
 * What a thrown ApiError says about one field of the request (its name as the
 * request sends it, e.g. `tagName`), or '' when it says nothing (or the value
 * isn't an ApiError).
 */
export function getFieldError(err: unknown, field: string): string {
  return err instanceof ApiError ? (err.fields[field]?.[0] ?? '') : ''
}

const STATUS_MESSAGES: Partial<Record<number, string>> = {
  400: 'The request was invalid.',
  401: 'Authentication is required. Please log in.',
  403: 'You do not have permission to perform this action.',
  404: 'The requested resource was not found.',
  409: 'That conflicts with something that already exists.',
  422: 'Some of what was sent is not valid.',
  429: 'Too many requests. Please wait a moment and try again.',
  500: 'An internal server error occurred.',
}

/** A problem's `errors`, by field name: `body.tagName` is about `tagName`. */
function fieldMessages(errors: unknown): Partial<Record<string, string[]>> {
  const fields: Partial<Record<string, string[]>> = {}
  if (!Array.isArray(errors)) {
    return fields
  }
  for (const entry of errors as unknown[]) {
    if (typeof entry !== 'object' || entry === null) {
      continue
    }
    const { message, location } = entry as { message?: unknown; location?: unknown }
    if (typeof message === 'string' && typeof location === 'string') {
      const field = location.replace(/^(body|query|path|header)\./, '')
      ;(fields[field] ??= []).push(message)
    }
  }
  return fields
}

/**
 * Parse a failed API response into an ApiError. Every API error is an RFC 9457
 * problem details body: its `detail` is the message, its `type` names the failure
 * (a tag URI ending in the code, `tag:gallery.mathdad.me,2026:MediaNotFound`), and
 * a failed validation's `errors` are the field messages. A body that isn't one
 * falls back to a description of the HTTP status.
 */
async function parseApiError(response: Response): Promise<ApiError> {
  let code = 'UnknownError'
  let message = ''
  let fields: Partial<Record<string, string[]>> = {}

  try {
    const body: unknown = await response.json()
    if (typeof body === 'object' && body !== null) {
      const problem = body as { type?: unknown; detail?: unknown; errors?: unknown }
      if (typeof problem.type === 'string' && problem.type.startsWith(PROBLEM_TYPE)) {
        code = problem.type.slice(PROBLEM_TYPE.length)
      }
      if (typeof problem.detail === 'string') {
        message = problem.detail
      }
      fields = fieldMessages(problem.errors)
    }
  } catch {
    // Response body wasn't valid JSON — fall through to defaults
  }

  if (!message) {
    message = STATUS_MESSAGES[response.status] ?? `Request failed (HTTP ${response.status}).`
  }

  return new ApiError(response.status, code, message, fields)
}

let onUnauthorized: (() => void) | null = null

/**
 * Runs whenever the server answers 401: the browser's session signs no one
 * in (it ran out, signed out elsewhere, or a new password ended it).
 * The auth store uses it to forget the account. The session itself is a
 * cookie the browser sends and the page never sees.
 */
export function whenUnauthorized(handler: () => void): void {
  onUnauthorized = handler
}

/**
 * Read a successful response body as JSON. A `204 No Content` (and any other
 * empty body) resolves to `undefined` rather than throwing on `response.json()`.
 * The `T | undefined` return is deliberate: callers must handle the empty case,
 * which keeps the defensive `?.`/`??` guards at call sites correct (and lets
 * `no-unnecessary-condition` stay on).
 */
async function parseJsonBody<T>(response: Response): Promise<T | undefined> {
  if (response.status === 204) {
    return undefined
  }
  const text = await response.text()
  return text ? (JSON.parse(text) as T) : undefined
}

/** A page of a longer list: its body, and how many items the whole list holds. */
interface Page<T> {
  data: T | undefined
  /** The list's size (the X-Total-Count header), or null when the response doesn't say. */
  total: number | null
}

export function useApi() {
  async function send(url: string, options: RequestInit = {}): Promise<Response> {
    // The session cookie goes with every request to this origin.
    const response = await fetch(`${API_BASE_URL}${url}`, {
      credentials: 'same-origin',
      ...options,
    })
    if (!response.ok) {
      if (response.status === 401) {
        onUnauthorized?.()
      }
      throw await parseApiError(response)
    }
    return response
  }

  async function request<T>(url: string, options: RequestInit = {}): Promise<T | undefined> {
    return parseJsonBody<T>(await send(url, options))
  }

  function get<T>(url: string): Promise<T | undefined> {
    return request<T>(url)
  }

  /** A GET of a paged list, with the list's size from X-Total-Count. */
  async function getPage<T>(url: string): Promise<Page<T>> {
    const response = await send(url)
    const header = response.headers.get('X-Total-Count')
    const total = header === null || header === '' ? Number.NaN : Number(header)
    return { data: await parseJsonBody<T>(response), total: Number.isFinite(total) ? total : null }
  }

  function post<T>(url: string, body: unknown): Promise<T | undefined> {
    return request<T>(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  }

  /** A PUT with a body, or without one for "add this" (a list item, a blocked tag). */
  function put<T>(url: string, body?: unknown): Promise<T | undefined> {
    return request<T>(url, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  }

  function patch<T>(url: string, body: unknown): Promise<T | undefined> {
    return request<T>(url, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  }

  function del<T>(url: string, body?: unknown): Promise<T | undefined> {
    return request<T>(url, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    })
  }

  /** A multipart POST; the browser sets its Content-Type, with the boundary. */
  function upload<T>(url: string, formData: FormData): Promise<T | undefined> {
    return request<T>(url, { method: 'POST', body: formData })
  }

  return { get, getPage, post, put, patch, del, upload }
}
