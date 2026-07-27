<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Các giai đoạn xử lý của một keyword analysis operation.
 */
enum KeywordAnalysisStage: string
{
    case Normalizing = 'normalizing';
    case ClassifyingIntent = 'classifying_intent';
    case MappingContent = 'mapping_content';
    case Clustering = 'clustering';
    case BuildingTopics = 'building_topics';
    case Scoring = 'scoring';
    case DetectingCannibalization = 'detecting_cannibalization';
    case Completed = 'completed';
    case Failed = 'failed';
}
