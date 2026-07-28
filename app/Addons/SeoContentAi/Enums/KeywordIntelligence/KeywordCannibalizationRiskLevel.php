<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Mức độ rủi ro cannibalization.
 */
enum KeywordCannibalizationRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
