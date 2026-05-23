import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'app/Addons/SeoContentAi/resources/js/task-builder.jsx',
                'app/Addons/SeoContentAi/resources/js/article-editor.jsx',
                'app/Addons/SeoContentAi/resources/js/article-seo-preview.jsx',
                'app/Addons/SeoContentAi/resources/css/media-library.css',
                'app/Addons/SeoContentAi/resources/js/watermark-editor-page.jsx',
                'app/Addons/SeoContentAi/resources/css/watermark-editor.css',
                'app/Addons/SeoContentAi/resources/css/image-optimization-settings.css',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@seo-addon': path.resolve(__dirname, 'app/Addons/SeoContentAi/resources/js'),
        },
    },
});
