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
                'app/Addons/SeoContentAi/resources/js/task-builder.jsx',
                'app/Addons/SeoContentAi/resources/js/automation-workflow-builder.jsx',
                'app/Addons/SeoContentAi/resources/css/automation-workflow-builder.css',
                'app/Addons/SeoContentAi/resources/js/automation-workflow-viewer.jsx',
                'app/Addons/SeoContentAi/resources/css/automation-workflow-viewer.css',
                'app/Addons/SeoContentAi/resources/js/article-editor.jsx',
                'app/Addons/SeoContentAi/resources/js/article-media-picker-cache-bootstrap.js',
                'app/Addons/SeoContentAi/resources/css/article-edit-page.css',
                'app/Addons/SeoContentAi/resources/js/article-seo-preview.jsx',
                'app/Addons/SeoContentAi/resources/js/keyword-detail-panel.jsx',
                'app/Addons/SeoContentAi/resources/js/keyword-destinations-modal.jsx',
                'app/Addons/SeoContentAi/resources/css/media-library.css',
                'app/Addons/SeoContentAi/resources/css/image-splitter.css',
                'app/Addons/SeoContentAi/resources/js/media-library-actions.js',
                'app/Addons/SeoContentAi/resources/js/media-library-page.jsx',
                'app/Addons/SeoContentAi/resources/js/watermark-editor-page.jsx',
                'app/Addons/SeoContentAi/resources/css/watermark-editor.css',
                'app/Addons/SeoContentAi/resources/css/image-optimization-settings.css',
                'app/Addons/SeoContentAi/resources/js/media-image-editor-page.jsx',
                'app/Addons/SeoContentAi/resources/css/ai-result.css',
                'app/Addons/SeoContentAi/resources/css/project-run-step.css',
                'app/Addons/SeoContentAi/resources/css/project-run-queue.css',
                'app/Addons/SeoContentAi/resources/js/project-run-queue.js',
                'app/Addons/SeoContentAi/resources/css/global-ai-chat.css',
                'app/Addons/SeoContentAi/resources/css/agent-workspace.css',
                'app/Addons/SeoContentAi/resources/js/agent/command-catalog.js',
                'app/Addons/SeoContentAi/resources/js/performance-hub-gsc-chart.js',
                'app/Addons/SeoContentAi/resources/js/utils/systemDateTime.js',
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
            '@seo-addon': path.resolve(__dirname, 'app/Addons/SeoContentAi/resources/js'),
        },
    },
});
