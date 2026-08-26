import { useAuth } from '@/auth/AuthContext';
import { Redirect, Stack } from 'expo-router';

/**
 * Everything in this group requires a signed-in user. The group owns "/", so
 * the app opens on the home screen when there is a valid token and bounces to
 * login when there is not.
 */
export default function AppLayout() {
    const { user } = useAuth();

    if (!user) return <Redirect href="/login" />;

    return <Stack screenOptions={{ headerShown: false }} />;
}
