const API_BASE_URL = '/api'

/**
 * Custom error class that carries structured API error info.
 */
export class ApiError extends Error {
  /** HTTP status code */
  status: number
  /** Machine-readable error code from the API (e.g. 'MediaNotFound') */
  code: string
  /** A failed validation's messages, by the field they are about (e.g. `tag_name`). */
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
 * What a thrown ApiError says about one field of the request, or '' when it
 * says nothing (or the value isn't an ApiError).
 */
export function getFieldError(err: unknown, field: string): string {
  return err instanceof ApiError ? (err.fields[field]?.[0] ?? '') : ''
}

/**
 * Parse a failed API response into an ApiError.
 * Attempts to read the JSON body for a human-readable `message` field;
 * falls back to a generic description based on the HTTP status code.
 */
async function parseApiError(response: Response, _url: string): Promise<ApiError> {
  let code = 'UnknownError'
  let message = ''
  let fields: Partial<Record<string, string[]>> = {}

  try {
    const body = await response.json()
    if (body?.error) {
      code = body.error
    }
    if (body?.message) {
      message = body.message
    }
    if (body?.errors && typeof body.errors === 'object') {
      fields = body.errors
    }
  } catch {
    // Response body wasn't valid JSON — fall through to defaults
  }

  if (!message) {
    switch (response.status) {
      case 400:
        message = 'The request was invalid.'
        break
      case 401:
        message = 'Authentication is required. Please log in.'
        break
      case 403:
        message = 'You do not have permission to perform this action.'
        break
      case 404:
        message = 'The requested resource was not found.'
        break
      case 429:
        message = 'Too many requests. Please wait a moment and try again.'
        break
      case 500:
        message = 'An internal server error occurred.'
        break
      default:
        message = `Request failed (HTTP ${response.status}).`
    }
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

export function useApi() {
  async function request<T>(url: string, options: RequestInit = {}): Promise<T | undefined> {
    // The session cookie goes with every request to this origin.
    const response = await fetch(`${API_BASE_URL}${url}`, {
      credentials: 'same-origin',
      ...options,
    })
    if (!response.ok) {
      if (response.status === 401) {
        onUnauthorized?.()
      }
      throw await parseApiError(response, url)
    }
    return parseJsonBody<T>(response)
  }

  function get<T>(url: string): Promise<T | undefined> {
    return request<T>(url)
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

  return { get, post, put, patch, del, upload }
}
