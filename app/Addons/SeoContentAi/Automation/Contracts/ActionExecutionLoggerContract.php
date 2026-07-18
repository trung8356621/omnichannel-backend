<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Contracts;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;

interface ActionExecutionLoggerContract
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function start(
        ActionContext $context,
        string $actionKey,
        ?string $entityType,
        ?int $entityId,
        array $input,
    ): void;

    public function finish(string $executionId, ActionResult $result): void;
}
