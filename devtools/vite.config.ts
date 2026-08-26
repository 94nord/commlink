import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [react()],
    server: {
        host: '0.0.0.0',
        // 5173 is web-dev's.
        port: 5174,
        strictPort: true,
    },
});
