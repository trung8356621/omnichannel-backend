<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class BootstrapSiteSyncCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $force = false,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.bootstrap';
    }
}
