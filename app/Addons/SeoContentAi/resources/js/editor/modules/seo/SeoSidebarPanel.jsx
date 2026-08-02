import React, { lazy, Suspense } from 'react';
import { useEditorHostApi } from '../../host/EditorHostApiContext';
import { t } from '../../../utils/i18n';

const SeoModule = lazy(() => import('../../../modules/SeoModule'));

export function SeoSidebarPanel() {
    const api = useEditorHostApi();
    const seo = api.seo || {};

    if (seo.error) {
        return (
            <div className="seo-module-error p-3 text-sm">
                <p className="mb-2">{seo.error}</p>
                {typeof seo.onRetry === 'function' ? (
                    <button
                        type="button"
                        className="rounded bg-primary-600 px-3 py-1.5 text-white"
                        onClick={seo.onRetry}
                    >
                        {t('editor_module_error_retry')}
                    </button>
                ) : null}
            </div>
        );
    }

    return (
        <Suspense fallback={<div className="seo-module-loading p-3 text-sm">{t('editor_module_loading')}</div>}>
            <SeoModule
                focusKeyword={seo.focusKeyword}
                analysis={seo.analysis}
                seoScoringRules={seo.seoScoringRules}
                seoRuleMessages={seo.seoRuleMessages}
                loading={seo.loading}
                analyzing={seo.analyzing}
                stale={seo.stale}
                analyzeError={seo.analyzeError}
                onAnalyzeClick={seo.onAnalyzeClick}
                onViolationAction={seo.onViolationAction}
                canGenerateFaq={seo.canGenerateFaq}
                canGenerateFeaturedSnippet={seo.canGenerateFeaturedSnippet}
            />
        </Suspense>
    );
}
