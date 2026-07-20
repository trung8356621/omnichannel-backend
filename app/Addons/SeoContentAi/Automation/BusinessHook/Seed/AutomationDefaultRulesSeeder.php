<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Seed;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationNodeType;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationTriggerType;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationWorkflowMode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationGraphRuleService;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationRuleService;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationVersionService;

final class AutomationDefaultRulesSeeder
{
    public function __construct(
        private readonly AutomationRuleService $ruleService,
        private readonly AutomationGraphRuleService $graphRuleService,
        private readonly AutomationVersionService $versionService,
    ) {}

    public function seed(): void
    {
        $this->seedIfMissing(
            code: 'sync-article-to-wordpress',
            data: [
                'code' => 'sync-article-to-wordpress',
                'name' => 'Sync article to WordPress',
                'description' => 'When article completed and has site, sync to WordPress. DISABLED by default.',
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => [
                    'all' => [
                        [
                            'field' => 'event.site_id',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::WordpressArticleSync->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => ['mode' => 'sync'],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'notify-workflow-failure',
            data: [
                'code' => 'notify-workflow-failure',
                'name' => 'Notify workflow failure',
                'description' => 'Notify when content project task fails. DISABLED by default.',
                'event_name' => BusinessEventName::ContentProjectTaskFailed->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::NotificationSend->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'input_mapping' => [
                        'message' => 'Task {{ payload.task_id }} failed',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'dispatch-publish-request',
            data: [
                'code' => 'dispatch-publish-request',
                'name' => 'Dispatch publish request',
                'description' => 'article.completed → wordpress.sync_requested. DISABLED by default.',
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => false,
                'priority' => 200,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::AutomationDispatchEvent->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'settings' => [
                        'event_name' => BusinessEventName::WordpressSyncRequested->value,
                    ],
                ],
            ],
        );

        $this->seedArticleCompletePipelineGraph();
    }

    private function seedArticleCompletePipelineGraph(): void
    {
        $code = 'article-complete-pipeline-graph';
        $existing = AutomationRule::query()->where('code', $code)->first();
        if ($existing instanceof AutomationRule && $existing->nodes()->exists()) {
            return;
        }

        $rule = $existing instanceof AutomationRule
            ? $existing
            : $this->ruleService->createRule([
                'code' => $code,
                'name' => 'Article complete pipeline (graph)',
                'description' => 'Sample graph: condition → delay → WP sync → branches. DISABLED.',
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => false,
                'priority' => 150,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'workflow_mode' => AutomationWorkflowMode::Graph->value,
                'trigger_type' => AutomationTriggerType::Event->value,
                'conditions' => null,
            ], []);

        if (! $rule->isGraphMode()) {
            $rule->forceFill(['workflow_mode' => AutomationWorkflowMode::Graph->value])->save();
        }

        $this->graphRuleService->syncGraph($rule, [
            ['node_key' => 'trigger', 'node_type' => AutomationNodeType::Trigger->value, 'name' => 'Trigger', 'position' => 0, 'is_enabled' => true],
            ['node_key' => 'check_post_type', 'node_type' => AutomationNodeType::Condition->value, 'name' => 'post_type == post', 'position' => 1, 'is_enabled' => true, 'config' => ['conditions' => ['all' => [['field' => 'subject.post_type', 'operator' => 'equals', 'value' => 'post']]]]],
            ['node_key' => 'delay_10s', 'node_type' => AutomationNodeType::Delay->value, 'name' => 'Delay 10s', 'position' => 2, 'is_enabled' => true, 'config' => ['seconds' => 10]],
            ['node_key' => 'wp_sync', 'node_type' => AutomationNodeType::Action->value, 'name' => 'WordPress sync', 'action_code' => AutomationActionCode::WordpressArticleSync->value, 'position' => 3, 'is_enabled' => true, 'input_mapping' => ['article_id' => '{{ payload.article_id }}'], 'settings' => ['mode' => 'sync'], 'config' => ['retry' => ['max_attempts' => 3, 'backoff_seconds' => [60, 300, 900]]]],
            ['node_key' => 'notify_fail', 'node_type' => AutomationNodeType::Action->value, 'name' => 'Notify failure', 'action_code' => AutomationActionCode::NotificationSend->value, 'position' => 4, 'is_enabled' => true, 'input_mapping' => ['message' => 'WP sync failed for article {{ payload.article_id }}']],
            ['node_key' => 'dispatch_synced', 'node_type' => AutomationNodeType::DispatchEvent->value, 'name' => 'Dispatch synced', 'position' => 5, 'is_enabled' => true, 'settings' => ['event_name' => BusinessEventName::WordpressSynced->value]],
            ['node_key' => 'end_ok', 'node_type' => AutomationNodeType::End->value, 'name' => 'End OK', 'position' => 6, 'is_enabled' => true],
            ['node_key' => 'end_fail', 'node_type' => AutomationNodeType::End->value, 'name' => 'End fail', 'position' => 7, 'is_enabled' => true],
            ['node_key' => 'end_skip', 'node_type' => AutomationNodeType::End->value, 'name' => 'End skip', 'position' => 8, 'is_enabled' => true],
        ], [
            ['from_node_key' => 'trigger', 'to_node_key' => 'check_post_type', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'check_post_type', 'to_node_key' => 'delay_10s', 'branch' => AutomationEdgeBranch::True->value, 'priority' => 100],
            ['from_node_key' => 'check_post_type', 'to_node_key' => 'end_skip', 'branch' => AutomationEdgeBranch::False->value, 'priority' => 100],
            ['from_node_key' => 'delay_10s', 'to_node_key' => 'wp_sync', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'wp_sync', 'to_node_key' => 'dispatch_synced', 'branch' => AutomationEdgeBranch::Success->value, 'priority' => 100],
            ['from_node_key' => 'wp_sync', 'to_node_key' => 'notify_fail', 'branch' => AutomationEdgeBranch::Failure->value, 'priority' => 100],
            ['from_node_key' => 'dispatch_synced', 'to_node_key' => 'end_ok', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'notify_fail', 'to_node_key' => 'end_fail', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
        ]);

        if ($rule->published_version_id === null) {
            $this->versionService->publish($rule);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $actions
     */
    private function seedIfMissing(string $code, array $data, array $actions): void
    {
        if (AutomationRule::query()->where('code', $code)->exists()) {
            return;
        }

        $this->ruleService->createRule($data, $actions);
    }
}
