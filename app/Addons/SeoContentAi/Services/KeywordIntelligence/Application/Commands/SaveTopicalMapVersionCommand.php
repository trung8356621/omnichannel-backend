<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class SaveTopicalMapVersionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly ?string $mode = null,
    ) {}

    public function name(): string
    {
        return 'keyword_intelligence.save_map_version';
    }
}
