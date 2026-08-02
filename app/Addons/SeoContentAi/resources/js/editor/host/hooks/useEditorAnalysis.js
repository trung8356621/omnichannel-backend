import { useMemo } from 'react';
import { useEditorHostApiOptional } from '../EditorHostApiContext';

/**
 * Phase 6C.4 — SEO analysis slice from host bag (read-only).
 */
export function useEditorAnalysis() {
    const host = useEditorHostApiOptional();
    return useMemo(() => ({
        focusKeyword: host?.seo?.focusKeyword ?? '',
        analysis: host?.seo?.analysis ?? null,
        loading: Boolean(host?.seo?.loading || host?.seo?.analyzing),
        error: host?.seo?.error ?? host?.seo?.analyzeError ?? null,
        onAnalyze: host?.seo?.onAnalyzeClick,
        onViolationAction: host?.seo?.onViolationAction,
    }), [host]);
}
