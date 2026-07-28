<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SerpIntelligence\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ImportSerpSnapshotCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $queryRef,
        public readonly string $payload,
        public readonly string $format = 'json',
        public readonly bool $preview = false,
    ) {}

    public function name(): string
    {
        return 'serp_intelligence.import_snapshot';
    }
}
