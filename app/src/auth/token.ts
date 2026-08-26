import * as SecureStore from 'expo-secure-store';

/**
 * The device's bearer token, in the Keychain (iOS) or Keystore (Android)
 * rather than plain storage — it is a long-lived credential for the firm's
 * whole inbox.
 */
const KEY = 'api_token';

export async function getToken(): Promise<string | null> {
    return SecureStore.getItemAsync(KEY);
}

export async function setToken(token: string): Promise<void> {
    await SecureStore.setItemAsync(KEY, token);
}

export async function clearToken(): Promise<void> {
    await SecureStore.deleteItemAsync(KEY);
}
