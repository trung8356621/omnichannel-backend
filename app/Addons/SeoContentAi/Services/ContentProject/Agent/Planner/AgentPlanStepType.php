<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner;

final class AgentPlanStepType
{
    public const CAPABILITY = 'capability';

    public const WAIT_OPERATION = 'wait_operation';

    public const WAIT_CONDITION = 'wait_condition';
}
