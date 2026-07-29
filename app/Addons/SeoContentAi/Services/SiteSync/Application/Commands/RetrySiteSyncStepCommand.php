<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RetrySiteSyncStepCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.retry_sync_step';
    }
}
