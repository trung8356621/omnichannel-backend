<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Trạng thái review thủ công của một keyword.
 */
enum KeywordReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
