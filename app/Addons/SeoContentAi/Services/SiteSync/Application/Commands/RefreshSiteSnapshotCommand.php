<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RefreshSiteSnapshotCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.refresh_snapshot';
    }
}
