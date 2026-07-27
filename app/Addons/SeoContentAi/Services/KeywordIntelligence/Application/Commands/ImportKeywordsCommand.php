<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ImportKeywordsCommand implements ContentProjectCommand
{
    /**
     * @param  list<string>|list<array<string, mixed>>  $keywords
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $keywords,
        public readonly bool $preview = false,
        public readonly bool $keepDuplicates = false,
        public readonly string $source = 'manual',
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.import_keywords';
    }
}
