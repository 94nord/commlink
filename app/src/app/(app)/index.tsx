import { useAuth } from '@/auth/AuthContext';
import { ThemedText } from '@/components/themed-text';
import { ThemedView } from '@/components/themed-view';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useState } from 'react';
import { Pressable, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

/**
 * Placeholder home screen. Its only job is to prove the token round-trip:
 * everything shown here came back from GET /api/user with a bearer token the
 * device stored itself. The inbox replaces it in the next slice.
 */
export default function Home() {
    const { user, logout } = useAuth();
    const theme = useTheme();
    const [signingOut, setSigningOut] = useState(false);

    async function signOut() {
        setSigningOut(true);
        try {
            await logout();
        } finally {
            setSigningOut(false);
        }
    }

    return (
        <ThemedView style={styles.container}>
            <SafeAreaView style={styles.safeArea}>
                <ThemedText type="subtitle">Signed in</ThemedText>

                <ThemedView type="backgroundElement" style={styles.card}>
                    <Field label="Name" value={user?.name} />
                    <Field label="Email" value={user?.email} />
                    <Field label="Admin" value={user?.is_admin ? 'Yes' : 'No'} />
                </ThemedView>

                <ThemedText type="small" themeColor="textSecondary" style={styles.note}>
                    The inbox lands here next.
                </ThemedText>

                <Pressable
                    onPress={signOut}
                    disabled={signingOut}
                    accessibilityRole="button"
                    style={[styles.button, { backgroundColor: theme.backgroundSelected }, signingOut && styles.buttonDisabled]}
                >
                    <ThemedText type="smallBold">{signingOut ? 'Logging out…' : 'Log out'}</ThemedText>
                </Pressable>
            </SafeAreaView>
        </ThemedView>
    );
}

function Field({ label, value }: { label: string; value?: string }) {
    return (
        <ThemedView type="backgroundElement" style={styles.field}>
            <ThemedText type="small" themeColor="textSecondary">
                {label}
            </ThemedText>
            <ThemedText type="smallBold">{value ?? '—'}</ThemedText>
        </ThemedView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
    },
    safeArea: {
        flex: 1,
        padding: Spacing.four,
        gap: Spacing.three,
    },
    card: {
        borderRadius: Spacing.three,
        padding: Spacing.three,
        gap: Spacing.two,
    },
    field: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: Spacing.three,
    },
    note: {
        flex: 1,
    },
    button: {
        borderRadius: Spacing.three,
        paddingVertical: Spacing.three,
        alignItems: 'center',
    },
    buttonDisabled: {
        opacity: 0.6,
    },
});
