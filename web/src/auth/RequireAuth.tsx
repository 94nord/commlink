import { useAuth } from '@/auth/AuthContext';
import { Navigate, Outlet, useLocation } from 'react-router-dom';

export function RequireAuth() {
    const { user, isLoading } = useAuth();
    const location = useLocation();

    if (isLoading) return null;

    if (!user) {
        return <Navigate to="/login" state={{ from: location }} replace />;
    }

    return <Outlet />;
}

export function RequireAdmin() {
    const { user, isLoading } = useAuth();

    if (isLoading) return null;

    if (!user?.is_admin) {
        return <Navigate to="/inbox" replace />;
    }

    return <Outlet />;
}
