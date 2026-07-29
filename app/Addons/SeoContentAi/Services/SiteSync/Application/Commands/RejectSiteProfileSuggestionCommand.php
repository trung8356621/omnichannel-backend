<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class RejectSiteProfileSuggestionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $suggestionHash,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function name(): string
    {
        return 'site.reject_profile_suggestion';
    }
}
