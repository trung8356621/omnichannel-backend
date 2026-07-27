<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\KeywordIntelligence;

/**
 * Trạng thái vòng đời của một Keyword Workspace.
 */
enum KeywordWorkspaceStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Archived = 'archived';
}
