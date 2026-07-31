<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

/** Canonical item actions — must match ContentProjectItemActionGuard. */
enum ContentProjectItemAction: string
{
    case Generate = 'generate';
    case Rerun = 'rerun';
    case StartReview = 'start_review';
    case Approve = 'approve';
    case Schedule = 'schedule';
    case Unschedule = 'unschedule';
    case PublishNow = 'publish_now';
    case RetryPublish = 'retry_publish';
    case SkipPublish = 'skip_publish';
    case CancelPublish = 'cancel_publish';
    case Archive = 'archive';
}
