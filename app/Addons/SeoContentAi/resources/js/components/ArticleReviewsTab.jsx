import React, { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Star } from 'lucide-react';
import { t } from '../utils/i18n';

function normalizeReviewsPayload(result) {
    if (Array.isArray(result)) {
        return result;
    }

    if (result && typeof result === 'object') {
        if (Array.isArray(result.reviews)) {
            return result.reviews;
        }

        if (Array.isArray(result.params?.reviews)) {
            return result.params.reviews;
        }
    }

    return null;
}

function formatReviewDate(raw) {
    const value = String(raw ?? '').trim();
    if (!value) {
        return '';
    }

    const parsed = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
}

function formatClock(raw) {
    const value = String(raw ?? '').trim();
    if (!value) {
        return '';
    }
    const parsed = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }
    return parsed.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function statusPresentation(review) {
    const status = String(review?.status ?? '');
    const scheduledAt = review?.scheduled_at;
    const nextRetryAt = review?.next_retry_at;
    const errorCode = String(review?.last_error_code ?? '');

    switch (status) {
        case 'draft':
            return { label: 'Local draft', hint: null };
        case 'pending_article':
            return { label: 'Waiting for article sync', hint: null };
        case 'pending_publish':
            return { label: 'Waiting for Automation', hint: null };
        case 'scheduled': {
            const clock = formatClock(scheduledAt);
            if (clock) {
                return { label: `Scheduled at ${clock}`, hint: null };
            }
            return { label: 'Scheduled', hint: null };
        }
        case 'publishing':
            return { label: 'Publishing', hint: null };
        case 'published':
            return { label: 'Published', hint: null };
        case 'failed_dispatch':
            return {
                label: 'Scheduling failed',
                hint: errorCode || review?.last_error_message || null,
            };
        case 'failed': {
            if (nextRetryAt) {
                const clock = formatClock(nextRetryAt);
                return {
                    label: 'Automatic retry pending',
                    hint: clock ? `Retry around ${clock}` : 'Retry scheduled',
                };
            }
            return { label: 'Failed', hint: review?.last_error_message || null };
        }
        case 'cancelled':
            return { label: 'Cancelled', hint: null };
        default:
            return { label: status ? String(status) : '', hint: null };
    }
}

function StarRating({ rating }) {
    const value = Number(rating);
    if (!Number.isFinite(value) || value <= 0) {
        return null;
    }

    return (
        <span className="seo-reviews-tab__stars" aria-label={`${value} / 5`}>
            {Array.from({ length: 5 }, (_, index) => (
                <Star
                    key={index}
                    size={14}
                    className={index < Math.round(value) ? 'is-filled' : ''}
                    fill={index < Math.round(value) ? 'currentColor' : 'none'}
                />
            ))}
        </span>
    );
}

/**
 * @param {{
 *   initialReviews?: Array,
 *   onRefresh?: () => Promise<Array|void>,
 *   canQuickCreate?: boolean,
 *   showConfigureReviews?: boolean,
 *   quickCreateConfigUrl?: string,
 *   onQuickCreate?: () => Promise<Array|void>,
 * }} props
 */
export default function ArticleReviewsTab({
    initialReviews = [],
    onRefresh,
    canQuickCreate = false,
    showConfigureReviews = false,
    quickCreateConfigUrl = '',
    onQuickCreate,
}) {
    const [reviews, setReviews] = useState(() => (
        Array.isArray(initialReviews) ? initialReviews : []
    ));
    const [refreshing, setRefreshing] = useState(false);
    const [quickCreating, setQuickCreating] = useState(false);

    const applyReviewsPayload = useCallback((result) => {
        const next = normalizeReviewsPayload(result);
        if (Array.isArray(next)) {
            setReviews(next);
        }
    }, []);

    useEffect(() => {
        setReviews(Array.isArray(initialReviews) ? initialReviews : []);
    }, [initialReviews]);

    useEffect(() => {
        const handler = (event) => {
            const detail = event?.detail ?? {};
            applyReviewsPayload(detail.reviews ?? detail.params?.reviews ?? detail);
        };
        window.addEventListener('virtual-reviews-updated', handler);
        return () => window.removeEventListener('virtual-reviews-updated', handler);
    }, [applyReviewsPayload]);

    const handleRefresh = useCallback(async () => {
        if (typeof onRefresh !== 'function' || refreshing) {
            return;
        }
        setRefreshing(true);
        try {
            const result = await onRefresh();
            applyReviewsPayload(result);
        } finally {
            setRefreshing(false);
        }
    }, [applyReviewsPayload, onRefresh, refreshing]);

    const handleQuickCreate = useCallback(async () => {
        if (typeof onQuickCreate !== 'function' || quickCreating) {
            return;
        }
        setQuickCreating(true);
        try {
            const result = await onQuickCreate();
            applyReviewsPayload(result);
        } finally {
            setQuickCreating(false);
        }
    }, [applyReviewsPayload, onQuickCreate, quickCreating]);

    return (
        <div className="seo-reviews-tab">
            <div className="seo-reviews-tab__header">
                <p className="seo-reviews-tab__summary">
                    {t('reviews_tab_summary', { count: reviews.length })}
                </p>
                <div className="seo-reviews-tab__actions">
                    {canQuickCreate && typeof onQuickCreate === 'function' ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__quick-create"
                            disabled={quickCreating || reviews.length > 0}
                            onClick={handleQuickCreate}
                        >
                            <Plus size={14} />
                            {t('reviews_tab_quick_create')}
                        </button>
                    ) : null}
                    {showConfigureReviews && quickCreateConfigUrl ? (
                        <a className="seo-reviews-tab__configure" href={quickCreateConfigUrl}>
                            {t('reviews_tab_configure')}
                        </a>
                    ) : null}
                    {typeof onRefresh === 'function' ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__refresh"
                            disabled={refreshing}
                            onClick={handleRefresh}
                        >
                            <RefreshCw size={14} className={refreshing ? 'is-spinning' : ''} />
                            {t('reviews_tab_refresh')}
                        </button>
                    ) : null}
                </div>
            </div>

            {reviews.length === 0 ? (
                <p className="seo-reviews-tab__empty">{t('reviews_tab_empty')}</p>
            ) : (
                <ul className="seo-reviews-tab__list">
                    {reviews.map((review, index) => {
                        const author = String(review?.author ?? '').trim() || t('reviews_tab_guest');
                        const content = String(review?.content ?? '').trim();
                        const dateLabel = formatReviewDate(review?.date);
                        const status = String(review?.status ?? '');
                        const reviewId = Number(review?.id ?? 0);
                        const presentation = statusPresentation(review);

                        return (
                            <li key={reviewId || `${author}-${index}-${dateLabel}`} className="seo-reviews-tab__item">
                                <div className="seo-reviews-tab__item-head">
                                    <strong className="seo-reviews-tab__author">{author}</strong>
                                    <StarRating rating={review?.rating} />
                                    {presentation.label ? (
                                        <span className={`seo-reviews-tab__status is-${status || 'unknown'}`}>
                                            {presentation.label}
                                        </span>
                                    ) : null}
                                    {dateLabel ? (
                                        <time className="seo-reviews-tab__date" dateTime={String(review?.date ?? '')}>
                                            {dateLabel}
                                        </time>
                                    ) : null}
                                </div>
                                {presentation.hint ? (
                                    <p className="seo-reviews-tab__hint">{presentation.hint}</p>
                                ) : null}
                                <p className="seo-reviews-tab__content">{content}</p>
                                {status === 'published' ? (
                                    <p className="seo-reviews-tab__meta">
                                        WP Comment ID: {String(review?.wp_comment_id ?? '—')}
                                        {review?.published_at ? ` · ${formatReviewDate(review.published_at)}` : ''}
                                    </p>
                                ) : null}
                                {status === 'failed' && review?.last_error_message ? (
                                    <p className="seo-reviews-tab__error">{String(review.last_error_message)}</p>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
