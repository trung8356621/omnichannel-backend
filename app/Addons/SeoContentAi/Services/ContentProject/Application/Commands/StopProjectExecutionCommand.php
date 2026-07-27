<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

final class StopProjectExecutionCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly string|int|null $executionRef = null,
        public readonly ?string $reason = null,
    ) {}

    public function name(): string
    {
        return 'content_project.stop_execution';
    }
}
