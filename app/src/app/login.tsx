import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { ThemedText } from '@/components/themed-text';
import { ThemedView } from '@/components/themed-view';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, StyleSheet, TextInput } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

/**
 * Native counterpart of web/src/pages/auth/login.tsx. No "remember me" — the
 * token it gets back is already long-lived — and no forgot-password link yet;
 * that flow is still web-only.
 */
export default function Login() {
    const { login } = useAuth();
    const router = useRouter();
    const theme = useTheme();

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    async function submit() {
        setProcessing(true);
        setErrors({});

        try {
            await login(email, password);
            router.replace('/');
        } catch (error) {
            if (error instanceof ApiError) {
                // A network failure has no field to hang off; say so on the
                // email field rather than swallowing it.
                setErrors(Object.keys(error.errors).length > 0 ? error.errors : { email: [error.message] });
            } else {
                setErrors({ email: ['Could not reach the server.'] });
            }
        } finally {
            setProcessing(false);
            setPassword('');
        }
    }

    return (
        <ThemedView style={styles.container}>
            <SafeAreaView style={styles.safeArea}>
                <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.form}>
                    <ThemedText type="subtitle">Log in</ThemedText>
                    <ThemedText type="small" themeColor="textSecondary">
                        Enter your email and password below to log in.
                    </ThemedText>

                    <ThemedView style={styles.field}>
                        <ThemedText type="smallBold">Email address</ThemedText>
                        <TextInput
                            value={email}
                            onChangeText={setEmail}
                            autoCapitalize="none"
                            autoComplete="email"
                            keyboardType="email-address"
                            placeholder="email@example.com"
                            placeholderTextColor={theme.textSecondary}
                            editable={!processing}
                            style={[styles.input, { backgroundColor: theme.backgroundElement, color: theme.text }]}
                        />
                        <InputError message={errors.email?.[0]} />
                    </ThemedView>

                    <ThemedView style={styles.field}>
                        <ThemedText type="smallBold">Password</ThemedText>
                        <TextInput
                            value={password}
                            onChangeText={setPassword}
                            secureTextEntry
                            autoCapitalize="none"
                            autoComplete="current-password"
                            placeholder="Password"
                            placeholderTextColor={theme.textSecondary}
                            editable={!processing}
                            onSubmitEditing={submit}
                            style={[styles.input, { backgroundColor: theme.backgroundElement, color: theme.text }]}
                        />
                        <InputError message={errors.password?.[0]} />
                    </ThemedView>

                    <Pressable
                        onPress={submit}
                        disabled={processing}
                        accessibilityRole="button"
                        style={[styles.button, { backgroundColor: theme.backgroundSelected }, processing && styles.buttonDisabled]}
                    >
                        <ThemedText type="smallBold">{processing ? 'Logging in…' : 'Log in'}</ThemedText>
                    </Pressable>
                </KeyboardAvoidingView>
            </SafeAreaView>
        </ThemedView>
    );
}

function InputError({ message }: { message?: string }) {
    if (!message) return null;

    return (
        <ThemedText type="small" style={styles.error}>
            {message}
        </ThemedText>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
    },
    safeArea: {
        flex: 1,
        justifyContent: 'center',
    },
    form: {
        padding: Spacing.four,
        gap: Spacing.three,
    },
    field: {
        gap: Spacing.two,
    },
    input: {
        borderRadius: Spacing.two,
        paddingHorizontal: Spacing.three,
        paddingVertical: Spacing.three,
        fontSize: 16,
    },
    error: {
        color: '#dc2626',
    },
    button: {
        borderRadius: Spacing.three,
        paddingVertical: Spacing.three,
        alignItems: 'center',
        marginTop: Spacing.two,
    },
    buttonDisabled: {
        opacity: 0.6,
    },
});
