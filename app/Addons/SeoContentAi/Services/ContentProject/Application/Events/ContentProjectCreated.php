<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Events;

/** @phpstan-immutable */
final class ContentProjectCreated
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $siteId,
        public readonly ?int $actorId,
    ) {}
}
