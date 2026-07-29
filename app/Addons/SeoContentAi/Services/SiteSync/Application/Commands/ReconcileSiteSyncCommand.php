<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class ReconcileSiteSyncCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $mode = 'standard',
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.reconcile';
    }
}
