import { AuthProvider, useAuth } from '@/auth/AuthContext';
import { ThemedView } from '@/components/themed-view';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import { ActivityIndicator, StyleSheet, useColorScheme } from 'react-native';

// Nothing queries yet, but the inbox will, and web/ already uses TanStack
// Query — keeping the data layers the same shape in both apps.
const queryClient = new QueryClient();

export default function RootLayout() {
    const colorScheme = useColorScheme();

    return (
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                <ThemeProvider value={colorScheme === 'dark' ? DarkTheme : DefaultTheme}>
                    <Boot />
                </ThemeProvider>
            </AuthProvider>
        </QueryClientProvider>
    );
}

/**
 * Reading the stored token and checking it with the server takes a moment.
 * Hold the splash until then rather than mounting routes we would immediately
 * redirect away from — otherwise a signed-in user sees the login screen flash
 * past on every launch.
 */
function Boot() {
    const { isLoading } = useAuth();

    if (isLoading) {
        return (
            <ThemedView style={styles.loading}>
                <ActivityIndicator />
            </ThemedView>
        );
    }

    return <Stack screenOptions={{ headerShown: false }} />;
}

const styles = StyleSheet.create({
    loading: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
    },
});
