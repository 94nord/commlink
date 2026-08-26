import { api, ApiError, ensureCsrfCookie } from '@/api/client';
import { type User } from '@/types';
import { createContext, useContext, useEffect, useState } from 'react';

interface AuthContextValue {
    user: User | null;
    isLoading: boolean;
    login: (email: string, password: string) => Promise<void>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    async function refresh() {
        try {
            const current = await api.get<User>('/api/user');
            setUser(current);
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                setUser(null);
            } else {
                throw error;
            }
        }
    }

    useEffect(() => {
        (async () => {
            await ensureCsrfCookie();
            await refresh();
            setIsLoading(false);
        })();
    }, []);

    async function login(email: string, password: string) {
        await api.post('/api/login', { email, password });
        await refresh();
    }

    async function logout() {
        await api.post('/api/logout');
        setUser(null);
    }

    return <AuthContext.Provider value={{ user, isLoading, login, logout, refresh }}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used within an AuthProvider');
    return context;
}
