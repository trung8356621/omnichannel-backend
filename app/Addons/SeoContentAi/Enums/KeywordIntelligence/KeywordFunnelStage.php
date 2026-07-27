<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Vị trí trong funnel mua hàng của keyword.
 */
enum KeywordFunnelStage: string
{
    case Awareness = 'awareness';
    case Consideration = 'consideration';
    case Decision = 'decision';
    case Retention = 'retention';
    case Unknown = 'unknown';
}
