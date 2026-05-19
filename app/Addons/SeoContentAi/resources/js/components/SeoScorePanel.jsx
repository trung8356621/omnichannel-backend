import React from 'react';
import { CheckCircle2, AlertCircle, AlertTriangle, Link2, ExternalLink } from 'lucide-react';

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

function LinksSection({ extractedLinks }) {
    const internal = extractedLinks?.internal ?? [];
    const external = extractedLinks?.external ?? [];

    return (
        <div className="seo-links-panel space-y-4">
            <div>
                <h4 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2 flex items-center gap-1.5">
                    <Link2 size={16} />
                    Liên kết nội bộ ({internal.length})
                </h4>
                {internal.length === 0 ? (
                    <p className="text-sm text-gray-500 italic">Chưa có link nội bộ trong nội dung.</p>
                ) : (
                    <ul className="seo-link-list">
                        {internal.map((link, i) => (
                            <li key={`in-${i}`}>
                                <a href={link.href} target="_blank" rel="noopener noreferrer" className="seo-link-href">
                                    {link.href}
                                </a>
                                {link.text ? <span className="seo-link-text">{link.text}</span> : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div>
                <h4 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2 flex items-center gap-1.5">
                    <ExternalLink size={16} />
                    Liên kết ngoài ({external.length})
                </h4>
                {external.length === 0 ? (
                    <p className="text-sm text-gray-500 italic">Chưa có link ngoài.</p>
                ) : (
                    <ul className="seo-link-list">
                        {external.map((link, i) => (
                            <li key={`ex-${i}`}>
                                <a href={link.href} target="_blank" rel="noopener noreferrer" className="seo-link-href">
                                    {link.href}
                                </a>
                                {link.text ? <span className="seo-link-text">{link.text}</span> : null}
                                {link.is_nofollow ? (
                                    <span className="seo-link-badge">nofollow</span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

export default function SeoScorePanel({ focusKeyword, analysis, extractedLinks, loading, analyzing }) {
    const score = analysis?.score ?? 0;
    const good = analysis?.good ?? [];
    const errors = analysis?.errors ?? [];
    const warnings = analysis?.warnings ?? [];

    return (
        <div className="seo-score-panel">
            <div className="seo-score-header">
                <ScoreRing score={score} loading={loading || analyzing} />
                <div className="seo-score-meta">
                    <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold">
                        Điểm SEO
                    </p>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mt-1">
                        {analyzing ? 'Đang phân tích…' : 'Cập nhật theo nội dung hiện tại'}
                    </p>
                    {focusKeyword ? (
                        <p className="mt-2 text-sm">
                            <span className="text-gray-500">Focus keyword:</span>{' '}
                            <strong className="text-gray-900 dark:text-white">{focusKeyword}</strong>
                        </p>
                    ) : (
                        <p className="mt-2 text-sm text-rose-600 dark:text-rose-400">
                            Chưa gán từ khóa chính cho bài viết.
                        </p>
                    )}
                </div>
            </div>

            <div className="seo-score-checks space-y-4">
                <CheckList title="Đạt" icon={CheckCircle2} items={good} tone="good" />
                <CheckList title="Lỗi" icon={AlertCircle} items={errors} tone="error" />
                <CheckList title="Cảnh báo" icon={AlertTriangle} items={warnings} tone="warning" />
            </div>

            <div className="seo-score-links-section">
                <h3 className="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">
                    Liên kết trích xuất (seo_extracted_links)
                </h3>
                <LinksSection extractedLinks={extractedLinks} />
            </div>
        </div>
    );
}
