import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';

// Replace 'aero-enterprise-suite' with your actual system username if using Herd/Valet paths
const host = 'aero-enterprise-suite.test';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss()
    ],
    esbuild: {
        jsx: 'automatic',
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            'ziggy-js': resolve(__dirname, 'vendor/tightenco/ziggy'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-react': ['react', 'react-dom'],
                    'vendor-inertia': ['@inertiajs/react'],
                    'vendor-heroui': ['@heroui/react'],
                    'vendor-framer': ['framer-motion'],
                    'vendor-utils': ['axios', 'lodash'],
                },
                chunkFileNames: 'assets/js/[name]-[hash].js',
                entryFileNames: 'assets/js/[name]-[hash].js',
                assetFileNames: 'assets/[ext]/[name]-[hash].[ext]',
            },
        },
        chunkSizeWarningLimit: 600,
    },
    server: { 
        host,
        hmr: { host },
        https: {
            // Adjust these paths based on whether you use Laravel Herd, Valet, or mkcert
            // This example assumes Laravel Herd/Valet default certificate paths
            key: fs.readFileSync(`C:/laragon/etc/ssl/laragon.key`),
            cert: fs.readFileSync(`C:/laragon/etc/ssl/laragon.crt`),
        },
    },
});