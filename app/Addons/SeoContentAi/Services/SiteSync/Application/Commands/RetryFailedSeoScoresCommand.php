<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/** Retry Workspace SEO scores that previously failed. */
final class RetryFailedSeoScoresCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly ?int $runId = null,
        public readonly ?string $operationId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.score_retry_failed';
    }
}
