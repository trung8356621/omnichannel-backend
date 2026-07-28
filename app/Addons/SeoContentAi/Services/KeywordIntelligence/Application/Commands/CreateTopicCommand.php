<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateTopicCommand implements ContentProjectCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly array $attributes,
        public readonly ?string $parentTopicRef = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.create_topic';
    }
}
