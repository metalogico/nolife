let csrfToken = ''
export function setCsrfToken(token: string) {
  csrfToken = token
}
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (!(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  if (csrfToken) {
    headers.set('X-CSRF-TOKEN', csrfToken)
  }
  const response = await fetch('/api/v1/' + path, {
    ...options,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
  })
  if (response.status === 204) {
    return undefined as T
  }
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 401 || response.status === 419) {
      window.dispatchEvent(new CustomEvent('nolife:unauthorized'))
    }
    const firstError = Object.values(body.errors || {}).flat()[0]
    throw new ApiError(
      response.status,
      String(
        firstError ||
          body.message ||
          (response.status === 413
            ? 'The uploaded file is too large.'
            : 'Something went wrong. Please try again.'),
      ),
      body.errors || {},
    )
  }
  return body as T
}
