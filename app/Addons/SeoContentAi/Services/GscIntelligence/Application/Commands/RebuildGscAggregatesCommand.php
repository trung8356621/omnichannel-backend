<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RebuildGscAggregatesCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.rebuild_aggregates';
    }
}
