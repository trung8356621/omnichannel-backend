import React from 'react';
import { logModuleLoadError } from '../utils/articleEditorPayloadAdapters';
import { t } from '../utils/i18n';

/**
 * Isolates a heavy sidebar module failure from the core TipTap editor.
 * Auto-retries once when first mount throws (shortcode → FAQ race / stale chunk).
 */
export default class ArticleEditorModuleErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, message: '', autoRetryUsed: false };
    }

    static getDerivedStateFromError(error) {
        return {
            hasError: true,
            message: error instanceof Error ? error.message : String(error ?? 'Module error'),
        };
    }

    componentDidCatch(error) {
        logModuleLoadError({
            moduleName: this.props.moduleName ?? 'unknown',
            articleId: this.props.articleId ?? null,
            endpoint: this.props.endpoint ?? null,
            error,
        });

        const msg = error instanceof Error ? error.message : String(error ?? '');
        const looksLikeCachedRace = /reading ['"]cached['"]/i.test(msg) || msg.includes("'cached'");
        const allowAuto = this.props.autoRetryOnCachedError !== false;

        if (allowAuto && looksLikeCachedRace && !this.state.autoRetryUsed) {
            this.setState({ autoRetryUsed: true, hasError: false, message: '' });
            window.setTimeout(() => {
                if (typeof this.props.onRetry === 'function') {
                    this.props.onRetry();
                }
            }, 0);
        }
    }

    handleRetry = () => {
        this.setState({ hasError: false, message: '' });
        if (typeof this.props.onRetry === 'function') {
            this.props.onRetry();
        }
    };

    render() {
        if (this.state.hasError) {
            return (
                <div className="seo-module-error rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                    <p className="mb-2 font-medium">
                        {t('editor_module_error_title')}
                    </p>
                    <p className="mb-3 opacity-80">{this.state.message}</p>
                    <button
                        type="button"
                        className="rounded bg-red-700 px-3 py-1.5 text-white"
                        onClick={this.handleRetry}
                    >
                        {t('editor_module_error_retry')}
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}
