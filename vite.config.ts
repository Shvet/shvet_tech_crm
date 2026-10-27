/// <reference types="vitest/config" />
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/__tests__/setup.ts'],
        include: ['resources/js/__tests__/**/*.test.{ts,tsx}'],
    },
});
