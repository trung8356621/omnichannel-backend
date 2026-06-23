import React from 'react';
import { CheckCircle2, AlertCircle, AlertTriangle } from 'lucide-react';
import { t } from '../utils/i18n';
import { resolveScoringMessage } from '../utils/seoAnalyzer';

function scoreColor(score) {
    if (score >= 70) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-rose-600 dark:text-rose-400';
}

function scoreRingColor(score) {
    if (score >= 70) return '#10b981';
    if (score >= 50) return '#f59e0b';
    return '#f43f5e';
}

function ScoreRing({ score, loading }) {
    const value = typeof score === 'number' ? Math.max(0, Math.min(100, score)) : 0;
    const circumference = 2 * Math.PI * 52;
    const offset = circumference - (value / 100) * circumference;

    return (
        <div className="seo-score-ring-wrap">
            <svg className="seo-score-ring" viewBox="0 0 120 120" aria-hidden>
                <circle cx="60" cy="60" r="52" className="seo-score-ring-bg" />
                <circle
                    cx="60"
                    cy="60"
                    r="52"
                    className="seo-score-ring-fg"
                    style={{
                        stroke: scoreRingColor(value),
                        strokeDasharray: circumference,
                        strokeDashoffset: loading ? circumference : offset,
                    }}
                />
            </svg>
            <div className={`seo-score-ring-value ${scoreColor(value)}`}>
                {loading ? '…' : value}
            </div>
        </div>
    );
}

function resolveDisplayLine(item, scoringMessages) {
    if (typeof item === 'string') {
        return item;
    }

    if (item && typeof item === 'object') {
        const key = String(item.key ?? '');
        if (key !== '') {
            return resolveScoringMessage(key, scoringMessages, item.params ?? {});
        }

        if (typeof item.message === 'string' && item.message !== '') {
            return item.message;
        }
    }

    return String(item ?? '');
}

function resolveDisplayItems(items, scoringMessages, reasonKeys) {
    if (Array.isArray(items) && items.length > 0) {
        return items.map((item) => resolveDisplayLine(item, scoringMessages));
    }

    if (Array.isArray(reasonKeys) && reasonKeys.length > 0) {
        return reasonKeys.map((key) => resolveScoringMessage(key, scoringMessages));
    }

    return [];
}

function CheckList({ title, icon: Icon, items, tone }) {
    if (!items?.length) return null;

    const toneClass =
        tone === 'good'
            ? 'text-emerald-700 dark:text-emerald-300'
            : tone === 'error'
              ? 'text-rose-700 dark:text-rose-300'
              : 'text-amber-700 dark:text-amber-300';

    return (
        <div className="seo-check-list">
            <h4 className={`seo-check-list-title ${toneClass}`}>
                <Icon size={16} className="inline mr-1.5 -mt-0.5" />
                {title}
            </h4>
            <ul className="space-y-1.5">
                {items.map((item, i) => (
                    <li key={`${tone}-${i}`} className="text-sm text-gray-700 dark:text-gray-300 leading-snug">
                        {item}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function BreakdownSection({ breakdown, scoringMessages }) {
    if (!breakdown || typeof breakdown !== 'object') {
        return null;
    }

    const rows = Object.entries(breakdown);
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="seo-score-breakdown space-y-2">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{t('seo_score_breakdown_title')}</h3>
            <ul className="space-y-1.5">
                {rows.map(([name, item]) => {
                    const earned = Number(item?.earned ?? 0);
                    const max = Number(item?.max ?? 0);
                    const key = String(item?.key ?? '');
                    const label = resolveScoringMessage(key, scoringMessages, item?.params ?? {});

                    return (
                        <li key={name} className="text-sm text-gray-700 dark:text-gray-300 flex justify-between gap-3">
                            <span>{label}</span>
                            <span className="font-medium whitespace-nowrap">
                                {earned}/{max}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function SeoScorePanel({
    focusKeyword,
    analysis,
    scoringMessages = {},
    loading,
    analyzing,
}) {
    const score = analysis?.score ?? analysis?.seo_score ?? 0;
    const reasonKeys = analysis?.reason_keys ?? [];
    const good = resolveDisplayItems(analysis?.good, scoringMessages, []);
    const errors = resolveDisplayItems(analysis?.errors, scoringMessages, reasonKeys.filter((key) => !String(key).includes('.pass')));
    const warnings = resolveDisplayItems(analysis?.warnings, scoringMessages, []);
    const breakdown = analysis?.breakdown ?? null;

    return (
        <div className="seo-score-panel">
            <div className="seo-score-header">
                <ScoreRing score={score} loading={loading || analyzing} />
                <div className="seo-score-meta">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold">
                        {t('seo_score_title')}
                    </p>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mt-1">
                        {analyzing ? t('seo_score_analyzing') : t('seo_score_updated_by_content')}
                    </p>
                    {focusKeyword ? (
                        <p className="mt-2 text-sm">
                            <span className="text-gray-500">Focus keyword:</span>{' '}
                            <strong className="text-gray-900 dark:text-white">{focusKeyword}</strong>
                        </p>
                    ) : (
                        <p className="mt-2 text-sm text-rose-600 dark:text-rose-400">
                            {t('seo_score_missing_focus_keyword')}
                        </p>
                    )}
                </div>
            </div>

            <BreakdownSection breakdown={breakdown} scoringMessages={scoringMessages} />

            <div className="seo-score-checks space-y-4">
                <CheckList title={t('seo_score_good')} icon={CheckCircle2} items={good} tone="good" />
                <CheckList title={t('seo_score_errors')} icon={AlertCircle} items={errors} tone="error" />
                <CheckList title={t('seo_score_warnings')} icon={AlertTriangle} items={warnings} tone="warning" />
            </div>
        </div>
    );
}
