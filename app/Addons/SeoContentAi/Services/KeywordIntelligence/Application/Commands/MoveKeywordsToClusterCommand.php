<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class MoveKeywordsToClusterCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>  $keywordRefs
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $keywordRefs,
        public readonly string $destinationClusterRef,
        public readonly bool $forceReviewedMismatch = false,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.move_keywords';
    }
}
