import i18n from '@/i18n';

const API_URL = import.meta.env.VITE_API_URL;

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;

    constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }

    /** First validation message for a field, if any. */
    fieldError(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

export function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}

/** Sanctum SPA auth: fetch the CSRF cookie once before the first mutating request. */
export async function ensureCsrfCookie(): Promise<void> {
    await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: 'include' });
}

interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    body?: unknown;
}

async function request<T>(path: string, { method = 'GET', body }: RequestOptions = {}): Promise<T> {
    if (method !== 'GET') {
        await ensureCsrfCookie();
    }

    const isFormData = body instanceof FormData;

    // Tells the backend which language to answer in — it has no stored
    // preference of its own, only what this request carries.
    const headers: Record<string, string> = { Accept: 'application/json', 'Accept-Language': i18n.language };
    if (body !== undefined && !isFormData) headers['Content-Type'] = 'application/json';

    const xsrfToken = readCookie('XSRF-TOKEN');
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    const response = await fetch(`${API_URL}${path}`, {
        method,
        credentials: 'include',
        headers,
        body: isFormData ? (body as FormData) : body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (response.status === 422) {
        const payload = await response.json();
        throw new ApiError(422, payload.message ?? i18n.t('common:validation_failed'), payload.errors ?? {});
    }

    if (!response.ok) {
        throw new ApiError(response.status, response.statusText);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    return response.json() as Promise<T>;
}

export const api = {
    get: <T>(path: string) => request<T>(path),
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
    delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),
};

/** Absolute API URL, e.g. for <img src> pointing at an authenticated asset. */
export function apiUrl(path: string): string {
    return `${API_URL}${path}`;
}
