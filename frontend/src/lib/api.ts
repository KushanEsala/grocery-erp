import type { AuthUser } from './auth-types';

const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8008/api';

interface ApiResponse<T = unknown> {
  success: boolean;
  message: string;
  data?: T;
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  errors?: Record<string, string[]>;
}

class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;
  data?: unknown;

  constructor(
    message: string,
    status = 0,
    errors?: Record<string, string[]>,
    data?: unknown
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.data = data;
  }
}

export function getApiErrorMessage(
  error: unknown,
  fallback = 'An unexpected error occurred.'
) {
  if (!(error instanceof Error) || !error.message) {
    return fallback;
  }

  return toUserFriendlyErrorMessage(error.message, fallback);
}

function toUserFriendlyErrorMessage(
  message: string,
  fallback: string
): string {
  const normalizedMessage = message.trim();
  const lowerMessage = normalizedMessage.toLowerCase();

  if (
    lowerMessage.includes('cannot connect to the erp api') ||
    lowerMessage.includes('failed to fetch') ||
    lowerMessage.includes('networkerror') ||
    lowerMessage.includes('network request failed')
  ) {
    return 'Cannot connect to the ERP API. Start MySQL in XAMPP, run Laravel on the port configured by NEXT_PUBLIC_API_URL, then try again.';
  }

  if (
    lowerMessage.includes('returned an invalid response') ||
    lowerMessage.includes('unexpected token <') ||
    lowerMessage.includes('<!doctype html') ||
    lowerMessage.includes('<html')
  ) {
    return 'The API returned a web page instead of JSON. Check that NEXT_PUBLIC_API_URL points to the Laravel API, not the frontend.';
  }

  if (lowerMessage.includes('session expired')) {
    return 'Your session has expired. Please sign in again.';
  }

  if (
    lowerMessage.includes('frontend api url matches its port') ||
    lowerMessage.includes('laravel api is running')
  ) {
    return 'Cannot connect to the ERP API. Start MySQL in XAMPP, run Laravel on the port configured by NEXT_PUBLIC_API_URL, then try again.';
  }

  if (/https?:\/\//i.test(normalizedMessage)) {
    return fallback;
  }

  return normalizedMessage;
}

class ApiClient {
  private tokenKey = 'auth_token';
  private pendingGets = new Map<string, Promise<ApiResponse<unknown>>>();
  private getCache = new Map<string, { expiresAt: number; response: ApiResponse<unknown> }>();

  constructor(private baseUrl: string) {}

  private getToken(): string | null {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem(this.tokenKey);
  }

  setToken(token: string) {
    this.getCache.clear();
    localStorage.setItem(this.tokenKey, token);
  }

  clearToken() {
    this.getCache.clear();
    localStorage.removeItem(this.tokenKey);
  }

  private handleUnauthorized() {
    if (typeof window === 'undefined') return;

    const currentPath = window.location.pathname + window.location.search;
    if (currentPath !== '/login') {
      sessionStorage.setItem('redirect_after_login', currentPath);
    }

    this.clearToken();
    window.location.href = '/login';
  }

  private async request<T>(
    method: string,
    path: string,
    body?: Record<string, unknown>
  ): Promise<ApiResponse<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    const token = this.getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    let response: Response;

    try {
      response = await fetch(`${this.baseUrl}${path}`, {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch {
      throw new ApiError(
        `Cannot connect to the ERP API at ${this.baseUrl}. Check that the Laravel API is running and the frontend API URL matches its port.`
      );
    }

    const rawBody = await response.text();
    let data: ApiResponse<T>;

    try {
      data = rawBody
        ? (JSON.parse(rawBody) as ApiResponse<T>)
        : {
            success: response.ok,
            message: response.ok ? 'Request completed.' : 'Request failed.',
          };
    } catch {
      throw new ApiError(
        `The ERP API returned an invalid response (${response.status}).`,
        response.status,
        undefined,
        rawBody
      );
    }

    if (response.status === 401) {
      this.handleUnauthorized();
      throw new ApiError('Session expired. Redirecting to login.', 401);
    }

    if (!response.ok) {
      const firstError = data.errors
        ? Object.values(data.errors).find((messages) => messages.length > 0)?.[0]
        : undefined;

      throw new ApiError(
        firstError || data.message || 'Request failed.',
        response.status,
        data.errors,
        data
      );
    }

    return data;
  }

  async login(email: string, password: string) {
    const response = await this.request<{
      user: AuthUser;
      token: string;
      token_type: string;
    }>('POST', '/v1/login', { email, password, device_name: 'web' });

    if (response.success && response.data) {
      this.setToken(response.data.token);
    }

    return response;
  }

  async logout() {
    try {
      await this.request('POST', '/v1/logout');
    } finally {
      this.clearToken();
    }
  }

  async getUser() {
    return this.get<AuthUser>('/v1/user');
  }

  async get<T = unknown>(path: string) {
    const cached = this.getCache.get(path);
    if (cached && cached.expiresAt > Date.now()) {
      return cached.response as ApiResponse<T>;
    }
    const pending = this.pendingGets.get(path);
    if (pending) return pending as Promise<ApiResponse<T>>;

    const request = this.request<T>('GET', path);
    this.pendingGets.set(path, request as Promise<ApiResponse<unknown>>);
    try {
      const response = await request;
      this.getCache.set(path, { expiresAt: Date.now() + 1200, response });
      return response;
    } finally {
      this.pendingGets.delete(path);
    }
  }

  async post<T = unknown>(path: string, body: Record<string, unknown>) {
    this.getCache.clear();
    return this.request<T>('POST', path, body);
  }

  async put<T = unknown>(path: string, body: Record<string, unknown>) {
    this.getCache.clear();
    return this.request<T>('PUT', path, body);
  }

  async patch<T = unknown>(path: string, body: Record<string, unknown>) {
    this.getCache.clear();
    return this.request<T>('PATCH', path, body);
  }

  async delete<T = unknown>(path: string) {
    this.getCache.clear();
    return this.request<T>('DELETE', path);
  }

  async download(path: string, fallbackFilename?: string) {
    const headers: Record<string, string> = {
      Accept: 'application/octet-stream',
    };

    const token = this.getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    let response: Response;

    try {
      response = await fetch(`${this.baseUrl}${path}`, {
        method: 'GET',
        headers,
      });
    } catch {
      throw new ApiError(
        `Cannot connect to the ERP API at ${this.baseUrl}. Check that the Laravel API is running and the frontend API URL matches its port.`
      );
    }

    if (response.status === 401) {
      this.handleUnauthorized();
      throw new ApiError('Session expired. Redirecting to login.', 401);
    }

    if (!response.ok) {
      const rawBody = await response.text();

      try {
        const data = rawBody ? (JSON.parse(rawBody) as ApiResponse) : null;
        throw new ApiError(
          data?.message || 'Failed to download file.',
          response.status,
          data?.errors,
          data
        );
      } catch (error) {
        if (error instanceof ApiError) {
          throw error;
        }

        throw new ApiError(
          rawBody || 'Failed to download file.',
          response.status,
          undefined,
          rawBody
        );
      }
    }

    const blob = await response.blob();
    const disposition = response.headers.get('content-disposition');
    const filename =
      disposition?.match(/filename="?([^"]+)"?/)?.[1] ||
      fallbackFilename ||
      'download';

    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.URL.revokeObjectURL(url);
  }
}

export const api = new ApiClient(API_BASE_URL);
export { ApiError };
export type { ApiResponse };
