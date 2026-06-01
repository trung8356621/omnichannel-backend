import React, { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Star } from 'lucide-react';
import { t } from '../utils/i18n';

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

export default function ArticleReviewsTab({ initialReviews = [], onRefresh }) {
    const [reviews, setReviews] = useState(() => (
        Array.isArray(initialReviews) ? initialReviews : []
    ));
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        setReviews(Array.isArray(initialReviews) ? initialReviews : []);
    }, [initialReviews]);

    useEffect(() => {
        const onUpdated = (event) => {
            const detail = event?.detail ?? {};
            const next = detail.reviews ?? detail.params?.reviews;
            if (Array.isArray(next)) {
                setReviews(next);
            }
        };

        window.addEventListener('virtual-reviews-updated', onUpdated);

        return () => window.removeEventListener('virtual-reviews-updated', onUpdated);
    }, []);

    const handleRefresh = useCallback(async () => {
        if (typeof onRefresh !== 'function') {
            return;
        }

        setRefreshing(true);
        try {
            const next = await onRefresh();
            if (Array.isArray(next)) {
                setReviews(next);
            }
        } finally {
            setRefreshing(false);
        }
    }, [onRefresh]);

    return (
        <div className="seo-tab-panel seo-reviews-tab">
            <div className="seo-reviews-tab__header">
                <p className="seo-reviews-tab__summary">
                    {t('reviews_tab_summary', { count: reviews.length })}
                </p>
                {typeof onRefresh === 'function' ? (
                    <button
                        type="button"
                        className="seo-reviews-tab__refresh"
                        onClick={handleRefresh}
                        disabled={refreshing}
                    >
                        <RefreshCw size={14} className={refreshing ? 'is-spinning' : ''} />
                        {t('reviews_tab_refresh')}
                    </button>
                ) : null}
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
