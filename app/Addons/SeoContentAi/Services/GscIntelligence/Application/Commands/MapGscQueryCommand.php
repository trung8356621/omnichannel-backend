<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class MapGscQueryCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $normalizedQuery,
        public readonly ?string $keywordRef = null,
        public readonly ?string $clusterRef = null,
        public readonly ?string $topicRef = null
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.map_query';
    }
}
