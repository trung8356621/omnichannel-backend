<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class CreateGscPropertyCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly array $attributes
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.create_property';
    }
}
