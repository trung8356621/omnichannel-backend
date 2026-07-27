<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Loại ánh xạ giữa keyword và bài viết.
 */
enum KeywordArticleMappingType: string
{
    case ExistingRankTarget = 'existing_rank_target';
    case CurrentContent = 'current_content';
    case PlannedTarget = 'planned_target';
    case CannibalizationCandidate = 'cannibalization_candidate';
}
