<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Contracts;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;

interface BusinessAction
{
    public static function definition(): ActionDefinition;

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ActionContext $context, array $input): ActionResult;
}
