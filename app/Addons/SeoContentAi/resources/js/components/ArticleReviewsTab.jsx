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
        const onUpdated = (event) => {
            applyReviewsPayload(event?.detail ?? {});
        };

        window.addEventListener('virtual-reviews-updated', onUpdated);

        return () => window.removeEventListener('virtual-reviews-updated', onUpdated);
    }, [applyReviewsPayload]);

    const handleRefresh = useCallback(async () => {
        if (typeof onRefresh !== 'function') {
            return;
        }

        setRefreshing(true);
        try {
            const next = await onRefresh();
            applyReviewsPayload(next);
        } catch (error) {
            const message = String(error?.message ?? error ?? '').trim()
                || t('reviews_tab_refresh_failed');

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('reviews_tab_refresh'),
                        body: message,
                        status: 'danger',
                    },
                }),
            );
        } finally {
            setRefreshing(false);
        }
    }, [onRefresh, applyReviewsPayload]);

    const handleQuickCreate = useCallback(async () => {
        if (typeof onQuickCreate !== 'function' || quickCreating) {
            return;
        }

        setQuickCreating(true);
        try {
            const next = await onQuickCreate();
            applyReviewsPayload(next);
        } catch (error) {
            const message = String(error?.message ?? error ?? '').trim()
                || t('reviews_tab_quick_create_failed');

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('reviews_tab_quick_create'),
                        body: message,
                        status: 'danger',
                    },
                }),
            );
        } finally {
            setQuickCreating(false);
        }
    }, [onQuickCreate, quickCreating, applyReviewsPayload]);

    const showQuickCreateButton = canQuickCreate && reviews.length === 0 && typeof onQuickCreate === 'function';
    const showConfigureLink = showConfigureReviews && String(quickCreateConfigUrl ?? '').trim() !== '';

    return (
        <div className="seo-tab-panel seo-reviews-tab">
            <div className="seo-reviews-tab__header">
                <p className="seo-reviews-tab__summary">
                    {t('reviews_tab_summary', { count: reviews.length })}
                </p>
                <div className="seo-reviews-tab__actions">
                    {showQuickCreateButton ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__quick-create"
                            onClick={handleQuickCreate}
                            disabled={quickCreating || refreshing}
                            title={t('reviews_tab_quick_create_hint')}
                        >
                            <Plus size={14} className={quickCreating ? 'is-spinning' : ''} />
                            <span>
                                {quickCreating
                                    ? t('reviews_tab_quick_create_loading')
                                    : t('reviews_tab_quick_create')}
                            </span>
                        </button>
                    ) : null}
                    {showConfigureLink ? (
                        <a
                            href={quickCreateConfigUrl}
                            className="seo-reviews-tab__configure"
                            title={t('reviews_tab_configure_hint')}
                        >
                            {t('reviews_tab_configure')}
                        </a>
                    ) : null}
                    {typeof onRefresh === 'function' ? (
                        <button
                            type="button"
                            className="seo-reviews-tab__refresh"
                            onClick={handleRefresh}
                            disabled={refreshing || quickCreating}
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

                        return (
                            <li key={`${author}-${index}-${dateLabel}`} className="seo-reviews-tab__item">
                                <div className="seo-reviews-tab__item-head">
                                    <strong className="seo-reviews-tab__author">{author}</strong>
                                    <StarRating rating={review?.rating} />
                                    {dateLabel ? (
                                        <time className="seo-reviews-tab__date" dateTime={String(review?.date ?? '')}>
                                            {dateLabel}
                                        </time>
                                    ) : null}
                                </div>
                                <p className="seo-reviews-tab__content">{content}</p>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
