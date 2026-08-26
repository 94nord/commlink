import { clearToken, getToken } from '@/auth/token';

const API_URL = process.env.EXPO_PUBLIC_API_URL;

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

interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    body?: unknown;
    /** Skip the stored token — used by the login call itself. */
    anonymous?: boolean;
}

/**
 * Same surface as web/src/api/client.ts, so code written against one reads the
 * same against the other. The auth differs and only the auth: no CSRF cookie
 * and no credentials: 'include' here, because a native client carries a bearer
 * token instead of a session.
 */
async function request<T>(path: string, { method = 'GET', body, anonymous = false }: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    if (!anonymous) {
        const token = await getToken();
        if (token) headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${API_URL}${path}`, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (response.status === 422) {
        const payload = await response.json();
        throw new ApiError(422, payload.message ?? 'Validation failed', payload.errors ?? {});
    }

    // The token was revoked on the server (another device signed this one out,
    // or the login was deleted). Drop it so the app falls back to login rather
    // than retrying a credential that will never work again.
    if (response.status === 401 && !anonymous) {
        await clearToken();
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
    post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'method' | 'body'>) =>
        request<T>(path, { ...options, method: 'POST', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
    delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),
};
