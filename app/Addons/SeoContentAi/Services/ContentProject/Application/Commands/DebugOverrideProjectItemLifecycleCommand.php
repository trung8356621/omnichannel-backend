<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Commands;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Carbon\Carbon;

/**
 * Feature-flagged debug/recovery lifecycle override — no WordPress side effects.
 *
 * @param  list<int|string>  $itemRefs
 */
final class DebugOverrideProjectItemLifecycleCommand implements ContentProjectCommand
{
    /**
     * @param  list<int|string>  $itemRefs
     */
    public function __construct(
        public readonly string|int $projectRef,
        public readonly array $itemRefs,
        public readonly string $toLifecycle,
        public readonly ?Carbon $scheduledAt = null,
        public readonly ?string $note = null,
    ) {}

    public function name(): string
    {
        return 'content_project.debug_override_lifecycle';
    }
}
