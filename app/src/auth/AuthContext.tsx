import { api, ApiError } from '@/api/client';
import { clearToken, getToken, setToken } from '@/auth/token';
import { type User } from '@/types';
import * as Device from 'expo-device';
import { createContext, useContext, useEffect, useState } from 'react';
import { Platform } from 'react-native';

interface AuthContextValue {
    user: User | null;
    isLoading: boolean;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** Names the token on the server, so a lost phone can be revoked by itself. */
function deviceName(): string {
    return Device.deviceName ?? Device.modelName ?? `${Platform.OS} device`;
}

/**
 * Same shape as web/src/auth/AuthContext.tsx. The difference is where the
 * session lives: a bearer token in secure storage instead of a cookie the
 * browser manages, so boot has to read it back before it can ask who we are.
 */
export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    async function refresh() {
        try {
            setUser(await api.get<User>('/api/user'));
        } catch (error) {
            // 401 means no usable token; the client has already discarded it.
            if (error instanceof ApiError && error.status === 401) {
                setUser(null);
            } else {
                throw error;
            }
        }
    }

    useEffect(() => {
        (async () => {
            // No token at all: skip the round-trip and go straight to login.
            if (await getToken()) {
                await refresh();
            }
            setIsLoading(false);
        })();
    }, []);

    async function login(email: string, password: string) {
        const { token, user: authenticated } = await api.post<{ token: string; user: User }>(
            '/api/mobile/token',
            { email, password, device_name: deviceName() },
            { anonymous: true },
        );

        await setToken(token);
        setUser(authenticated);
    }

    async function logout() {
        try {
            await api.post('/api/mobile/logout');
        } finally {
            // Signed out locally either way — a failed revoke should not strand
            // someone in the app.
            await clearToken();
            setUser(null);
        }
    }

    return <AuthContext.Provider value={{ user, isLoading, login, logout, refresh }}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used within an AuthProvider');
    return context;
}
