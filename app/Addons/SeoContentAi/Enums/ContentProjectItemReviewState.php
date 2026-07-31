<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

/** Review dimension — mirrors ArticleReviewStatus (+ none when no article). */
enum ContentProjectItemReviewState: string
{
    case None = 'none';
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case ReviewArchived = 'review_archived';
}
