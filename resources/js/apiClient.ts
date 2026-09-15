export type ApiError = {
    message: string;
    errors?: Record<string, string[]>;
};

const apiBaseUrl = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/$/, '');

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(`${apiBaseUrl}${path}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...options.headers,
        },
    });

    const payload = (await response.json().catch(() => ({}))) as T | ApiError;

    if (!response.ok) {
        throw payload as ApiError;
    }

    return payload as T;
}

export const apiClient = {
    get<T>(path: string): Promise<T> {
        return request<T>(path);
    },
    post<T>(path: string, body: unknown): Promise<T> {
        return request<T>(path, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    },
};
