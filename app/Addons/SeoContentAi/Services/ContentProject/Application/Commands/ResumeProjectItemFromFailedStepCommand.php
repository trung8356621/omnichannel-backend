<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;

/**
 * Resume generation from first retryable failed step (canonical row Retry).
 *
 * @param  list<int|string>  $itemRefs
 */
final class ResumeProjectItemFromFailedStepCommand implements ContentProjectCommand
{
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly string $mode = 'full',
    ) {}

    public function name(): string
    {
        return 'content_project.resume_failed_step';
    }
}
