<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class GenerateProjectItemsCommand implements ContentProjectCommand
{
    /** @param list<int|string> $itemRefs */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs = [],
        public readonly string $mode = 'full',
        public readonly bool $technicalConfirmFullRerun = false,
    ) {}

    public function name(): string
    {
        return 'content_project.generate';
    }
}
