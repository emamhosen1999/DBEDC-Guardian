import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

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
        // Code-splitting optimization for faster route transitions
        rollupOptions: {
            output: {
                manualChunks: {
                    // Vendor chunks - cached separately for better performance
                    'vendor-react': ['react', 'react-dom'],
                    'vendor-inertia': ['@inertiajs/react'],
                    'vendor-heroui': ['@heroui/react'],
                    'vendor-framer': ['framer-motion'],
                    // Common utilities
                    'vendor-utils': ['axios', 'lodash'],
                },
                // Optimize chunk file naming for better caching
                chunkFileNames: 'assets/js/[name]-[hash].js',
                entryFileNames: 'assets/js/[name]-[hash].js',
                assetFileNames: 'assets/[ext]/[name]-[hash].[ext]',
            },
        },
        // Increase chunk size warning limit (optional)
        chunkSizeWarningLimit: 600,
    },
    server: { 
        host: 'localhost',
        hmr: { host: 'localhost' },
    },
});
