<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Loại node trong topical map.
 */
enum KeywordTopicType: string
{
    case Root = 'root';
    case Pillar = 'pillar';
    case Subtopic = 'subtopic';
    case Cluster = 'cluster';
    case FaqGroup = 'faq_group';
}
