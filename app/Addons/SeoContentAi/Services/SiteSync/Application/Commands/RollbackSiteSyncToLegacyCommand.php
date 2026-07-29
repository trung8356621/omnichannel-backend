<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RollbackSiteSyncToLegacyCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly ?string $reason = null,
        public readonly ?string $confirmationToken = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.rollback_legacy';
    }
}
