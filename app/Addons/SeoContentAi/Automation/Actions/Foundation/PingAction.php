<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Foundation;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\ActionRiskLevel;
use App\Addons\SeoContentAi\Automation\Enums\ActionSelectability;
use App\Addons\SeoContentAi\Automation\Enums\ActionSideEffect;

/**
 * Foundation smoke action — không side effect nghiệp vụ.
 */
final class PingAction implements BusinessAction
{
    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'automation.ping',
            name: 'Automation ping',
            description: 'Foundation health-check action (pure).',
            module: 'automation',
            sideEffect: ActionSideEffect::Pure,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::InternalOnly,
            inputSchema: [
                'message' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'pong' => ['type' => 'boolean'],
            ],
            supportsDryRun: true,
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        return ActionResult::success(
            output: [
                'pong' => true,
                'message' => (string) ($input['message'] ?? 'ok'),
                'execution_id' => $context->executionId,
            ],
        );
    }
}
