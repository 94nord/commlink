import { readCookie } from '@/api/client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const API_URL = import.meta.env.VITE_API_URL;

let echo: Echo<'reverb'> | null = null;

/**
 * One socket connection for the whole app, built the first time something
 * subscribes (always from behind the auth gate, so the session — and its
 * XSRF cookie — already exist).
 *
 * Channel authorization is a custom handler rather than Echo's built-in
 * `authEndpoint`/`auth.headers`, so it can go through the same `fetch`
 * shape as `api/client.ts` — `credentials: 'include'` plus a freshly read
 * `X-XSRF-TOKEN`, matching how every other request proves itself to
 * Sanctum, instead of a header captured once at construction time.
 */
export function getEcho(): Echo<'reverb'> {
    if (echo) return echo;

    echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: import.meta.env.VITE_REVERB_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                fetch(`${API_URL}/broadcasting/auth`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
                    },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error(response.statusText))))
                    .then((data) => callback(null, data))
                    .catch((error) => callback(error, null));
            },
        },
    });

    return echo;
}
