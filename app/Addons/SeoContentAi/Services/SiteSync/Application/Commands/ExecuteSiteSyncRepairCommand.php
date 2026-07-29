<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ExecuteSiteSyncRepairCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $planId,
        public readonly array $selectedIds = [],
        public readonly bool $dryRun = true,
        public readonly ?string $confirmationToken = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.execute_repair';
    }
}
