<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Contracts;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;

/**
 * Execution boundary cho HTTP controller / Filament caller vào Catalog BusinessAction.
 * Caller không được gọi ActionRunner trực tiếp — luôn qua dispatcher này.
 */
interface BusinessActionDispatcher
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function dispatch(string $actionKey, array $input, ActionContext $context): ActionResult;
}
