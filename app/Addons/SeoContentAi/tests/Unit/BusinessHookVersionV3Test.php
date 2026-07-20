<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationNodeType;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationRuleVersionStatus;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRuleNode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRuleVersion;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationVersionService;
use App\Addons\SeoContentAi\Automation\Exceptions\AutomationException;
use Tests\TestCase;

final class BusinessHookVersionV3Test extends TestCase
{
    public function test_draft_conflict_uses_expected_error_code(): void
    {
        $exception = new AutomationException(
            BusinessHookErrorCode::DraftConflict->value,
            'Draft revision conflict. Reload and retry.',
        );

        self::assertSame(BusinessHookErrorCode::DraftConflict->value, $exception->errorCode);
    }

    public function test_published_version_status_is_immutable_enum_value(): void
    {
        $version = new AutomationRuleVersion([
            'status' => AutomationRuleVersionStatus::Published->value,
            'version' => 2,
        ]);

        self::assertTrue($version->isPublished());
        self::assertFalse($version->isDraft());
        self::assertSame('published', AutomationRuleVersionStatus::Published->value);
    }

    public function test_validate_draft_returns_errors_from_graph_validator(): void
    {
        $service = app(AutomationVersionService::class);
        $rule = new AutomationRule(['id' => 1, 'workflow_mode' => 'graph']);
        // Two triggers — validator must reject.
        $rule->setRelation('nodes', collect([
            new AutomationRuleNode([
                'node_key' => 't1',
                'node_type' => AutomationNodeType::Trigger->value,
                'is_enabled' => true,
            ]),
            new AutomationRuleNode([
                'node_key' => 't2',
                'node_type' => AutomationNodeType::Trigger->value,
                'is_enabled' => true,
            ]),
        ]));
        $rule->setRelation('edges', collect());

        $result = $service->validateDraft($rule);

        self::assertFalse($result['valid']);
        self::assertNotEmpty($result['errors']);
        self::assertTrue(
            collect($result['errors'])->contains(
                static fn (string $e): bool => str_contains(strtolower($e), 'trigger'),
            ),
        );
    }

    public function test_archived_status_is_distinct_from_published_and_draft(): void
    {
        self::assertSame('archived', AutomationRuleVersionStatus::Archived->value);
        self::assertNotSame(AutomationRuleVersionStatus::Published->value, AutomationRuleVersionStatus::Draft->value);
    }
}
