import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    resolve: {
        alias: {
            tailwindcss: '/resources/css/war-tailwind-compat.css',
        },
    },
    plugins: [
        react(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/war/main.tsx'],
            refresh: true,
        }),
    ],
});
