<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;

/**
 * Force full site sync — traverse entire WP catalog; do not skip unchanged on fetch.
 */
final class ForceFullSiteSyncCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly bool $supersedeActive = true,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $operationId = null,
    ) {}

    public function name(): string
    {
        return 'site.sync.force_full';
    }

    public function mode(): string
    {
        return SiteSyncSchema::MODE_FORCE_FULL;
    }
}
