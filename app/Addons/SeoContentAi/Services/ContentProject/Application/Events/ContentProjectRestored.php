<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Events;

final class ContentProjectRestored
{
    public function __construct(
        public readonly int $projectId,
        public readonly ?int $actorId,
    ) {}
}
