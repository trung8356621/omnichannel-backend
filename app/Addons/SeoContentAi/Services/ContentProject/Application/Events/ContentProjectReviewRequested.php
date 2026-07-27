<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Events;

final class ContentProjectReviewRequested
{
    public function __construct(
        public readonly int $projectId,
        /** @var list<int> */
        public readonly array $itemIds,
    ) {}
}
