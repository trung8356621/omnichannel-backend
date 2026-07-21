<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ArticleProductReviewStatus: string
{
    case Draft = 'draft';
    case PendingArticle = 'pending_article';
    case PendingPublish = 'pending_publish';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case FailedDispatch = 'failed_dispatch';
    case Cancelled = 'cancelled';

    public function isPublishable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::PendingArticle,
            self::PendingPublish,
            self::Scheduled,
            self::Failed,
            self::FailedDispatch,
        ], true);
    }
}
