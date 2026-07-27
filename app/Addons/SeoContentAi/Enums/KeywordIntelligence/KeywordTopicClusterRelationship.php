<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Loại quan hệ giữa topic và cluster.
 */
enum KeywordTopicClusterRelationship: string
{
    case Primary = 'primary';
    case Supporting = 'supporting';
    case Related = 'related';
    case Faq = 'faq';
    case Comparison = 'comparison';
}
