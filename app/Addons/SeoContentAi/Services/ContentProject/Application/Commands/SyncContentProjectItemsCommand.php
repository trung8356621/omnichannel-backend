<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class SyncContentProjectItemsCommand implements ContentProjectCommand
{
    /** @param list<array<string, mixed>> $tasksData */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $tasksData,
    ) {}

    public function name(): string
    {
        return 'content_project.sync_items';
    }
}
