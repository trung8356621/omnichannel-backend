<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ReviewCannibalizationIssueCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $issueRef,
        /** @var 'reviewed'|'ignored'|'resolved' */
        public readonly string $action,
        public readonly ?string $resolutionCode = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.review_cannibalization';
    }
}
