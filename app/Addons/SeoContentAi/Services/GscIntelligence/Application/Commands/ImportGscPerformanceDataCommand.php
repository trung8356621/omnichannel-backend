<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ImportGscPerformanceDataCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $propertyRef,
        public readonly string $payload,
        public readonly bool $preview = false
    ) {}

    public function name(): string
    {
        return 'gsc_intelligence.import_performance';
    }
}
