import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import fs from 'node:fs';
import { defineConfig } from 'vite';

// Dev TLS: reuse the self-signed certificate nginx serves (make certs) so the
// HMR origin matches the https://localhost:8443 page and avoids mixed content.
const certFile = 'docker/nginx/certs/localhost.crt';
const keyFile = 'docker/nginx/certs/localhost.key';
const https = fs.existsSync(certFile) && fs.existsSync(keyFile) ? { cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) } : undefined;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,
        https,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // File events don't cross the live-mount boundary; poll instead.
            usePolling: process.env.VITE_POLLING === '1',
        },
    },
});
