<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Runtime;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessActionDispatcher;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Exceptions\AutomationException;

/**
 * Default BusinessActionDispatcher — wraps ActionRunner::run.
 * Translate AutomationException (validation/not-selectable/...) thành ActionResult::failure
 * để caller (controller/Filament) không phải try/catch domain exception riêng.
 */
final class CatalogBusinessActionDispatcher implements BusinessActionDispatcher
{
    public function __construct(
        private readonly ActionRunner $runner,
    ) {}

    public function dispatch(string $actionKey, array $input, ActionContext $context): ActionResult
    {
        try {
            return $this->runner->run($actionKey, $context, $input);
        } catch (AutomationException $exception) {
            return ActionResult::failure($exception->errorCode, $exception->getMessage());
        }
    }
}
