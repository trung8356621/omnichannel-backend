import React from 'react';
import { CheckCircle2, AlertCircle, AlertTriangle } from 'lucide-react';
import { t } from '../utils/i18n';

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

function ContentBonusRow({ item, faqCount }) {
    if (!item) {
        return null;
    }

    const passed = Boolean(item.passed);
    const points = typeof item.points === 'number' ? item.points : 0;
    const maxPoints = typeof item.max_points === 'number' ? item.max_points : 10;
    const toneClass = passed
        ? 'seo-content-bonus-row--pass'
        : 'seo-content-bonus-row--fail';
    const isFeaturedSnippet = item.key === 'featured_snippet';

    return (
        <div
            className={`seo-content-bonus-row ${toneClass}${isFeaturedSnippet ? ' is-clickable' : ''}`}
            role={isFeaturedSnippet ? 'button' : undefined}
            tabIndex={isFeaturedSnippet ? 0 : undefined}
            onClick={
                isFeaturedSnippet
                    ? () => {
                        window.dispatchEvent(new CustomEvent('seo-editor-scroll-to-featured-snippet-table'));
                    }
                    : undefined
            }
            onKeyDown={
                isFeaturedSnippet
                    ? (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            window.dispatchEvent(new CustomEvent('seo-editor-scroll-to-featured-snippet-table'));
                        }
                    }
                    : undefined
            }
            title={isFeaturedSnippet ? t('seo_score_featured_snippet_jump') : undefined}
        >
            <div className="seo-content-bonus-row__head">
                <span className="seo-content-bonus-row__label">{item.label}:</span>
                <span className="seo-content-bonus-row__points">
                    {points} / {maxPoints} {t('seo_score_points')}
                </span>
            </div>
            {item.key === 'faq' && typeof faqCount === 'number' ? (
                <p className="seo-content-bonus-row__meta">
                    {t('seo_score_faq_count')}: <strong>{faqCount}</strong>
                </p>
            ) : null}
            {item.message ? (
                <p className="seo-content-bonus-row__message">{item.message}</p>
            ) : null}
        </div>
    );
}

function ContentBonusSection({ contentBonus }) {
    const items = contentBonus?.items;
    if (!items) {
        return null;
    }

    const totalBonus =
        typeof contentBonus.total_bonus === 'number'
            ? contentBonus.total_bonus
            : (items.featured_snippet?.points ?? 0) + (items.faq?.points ?? 0);

    return (
        <div className="seo-content-bonus">
            <h3 className="seo-content-bonus__title">{t('seo_score_bonus_title')}</h3>
            <p className="seo-content-bonus__subtitle">
                {t('seo_score_bonus_subtitle_before')} <strong>{totalBonus}</strong> / 20 {t('seo_score_points')} {t('seo_score_bonus_subtitle_after')}
            </p>
            <ContentBonusRow item={items.featured_snippet} />
            <ContentBonusRow item={items.faq} faqCount={contentBonus.faq_count} />
        </div>
    );
}

export default function SeoScorePanel({
    focusKeyword,
    analysis,
    contentBonus: contentBonusProp,
    loading,
    analyzing,
}) {
    const score = analysis?.score ?? 0;
    const good = analysis?.good ?? [];
    const errors = analysis?.errors ?? [];
    const warnings = analysis?.warnings ?? [];
    const contentBonus = contentBonusProp ?? analysis?.content_bonus ?? null;

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

            <ContentBonusSection contentBonus={contentBonus} />

            <div className="seo-score-checks space-y-4">
                <CheckList title={t('seo_score_good')} icon={CheckCircle2} items={good} tone="good" />
                <CheckList title={t('seo_score_errors')} icon={AlertCircle} items={errors} tone="error" />
                <CheckList title={t('seo_score_warnings')} icon={AlertTriangle} items={warnings} tone="warning" />
            </div>
        </div>
    );
}
