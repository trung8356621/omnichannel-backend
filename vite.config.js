import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

const editorDebugBuild = process.env.VITE_EDITOR_DEBUG_BUILD === '1';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'addons/content-projects/resources/js/task-builder.jsx',
                'addons/content-projects/resources/js/automation-workflow-builder.jsx',
                'addons/content-projects/resources/css/automation-workflow-builder.css',
                'addons/content-projects/resources/js/automation-workflow-viewer.jsx',
                'addons/content-projects/resources/css/automation-workflow-viewer.css',
                'addons/content/resources/js/article-editor.jsx',
                'addons/media/resources/js/article-media-picker-cache-bootstrap.js',
                'addons/content/resources/css/article-edit-page.css',
                'addons/seo/resources/js/article-seo-preview.jsx',
                'addons/seo/resources/js/keyword-detail-panel.jsx',
                'addons/seo/resources/js/keyword-destinations-modal.jsx',
                'addons/media/resources/css/media-library.css',
                'addons/media/resources/css/image-splitter.css',
                'addons/media/resources/js/media-library-actions.js',
                'addons/media/resources/js/media-library-page.jsx',
                'addons/media/resources/js/watermark-editor-page.jsx',
                'addons/media/resources/css/watermark-editor.css',
                'addons/media/resources/css/image-optimization-settings.css',
                'addons/media/resources/js/media-image-editor-page.jsx',
                'addons/ai-prompt/resources/css/ai-result.css',
                'addons/content-projects/resources/css/project-run-step.css',
                'addons/content-projects/resources/css/project-run-queue.css',
                'addons/content-projects/resources/js/project-run-queue.js',
                'addons/ai-prompt/resources/css/global-ai-chat.css',
                'addons/agent/resources/css/agent-workspace.css',
                'addons/agent/resources/js/agent/command-catalog.js',
                'addons/search-intelligence/resources/js/performance-hub-gsc-chart.js',
                'addons/content/resources/js/utils/systemDateTime.js',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        // Temporary investigation only: VITE_EDITOR_DEBUG_BUILD=1 npm run build
        // Default production stays minified, no public sourcemaps.
        minify: editorDebugBuild ? false : undefined,
        sourcemap: editorDebugBuild,
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }

                    const parts = id.split('node_modules/')[1]?.split('/') ?? [];
                    const pkgName = parts[0]?.startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0];

                    // React core (avoid matching @tiptap/react, @react-aria, ...)
                    if (['react', 'react-dom', 'scheduler', 'use-sync-external-store'].includes(pkgName)) {
                        return 'react-vendor';
                    }

                    // Tiptap + ProseMirror
                    if (pkgName?.startsWith('@tiptap/') || pkgName?.startsWith('prosemirror-')) {
                        return 'tiptap-vendor';
                    }

                    return 'vendor';
                },
            },
        },
    },
    resolve: {
        alias: {
            '@content-addon': path.resolve(__dirname, 'addons/content/resources/js'),
            '@media-addon': path.resolve(__dirname, 'addons/media/resources/js'),
            '@seo-addon': path.resolve(__dirname, 'addons/seo/resources/js'),
            '@wordpress-addon': path.resolve(__dirname, 'addons/wordpress/resources/js'),
            '@publishing-addon': path.resolve(__dirname, 'addons/publishing/resources/js'),
            '@content-projects-addon': path.resolve(__dirname, 'addons/content-projects/resources/js'),
            '@search-intel-addon': path.resolve(__dirname, 'addons/search-intelligence/resources/js'),
            '@ai-prompt-addon': path.resolve(__dirname, 'addons/ai-prompt/resources/js'),
            '@agent-addon': path.resolve(__dirname, 'addons/agent/resources/js'),
            '@client-core': path.resolve(__dirname, 'resources/js/core'),
        },
    },
});
