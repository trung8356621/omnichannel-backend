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

        $this->seedIfMissing(
            code: 'seo-analysis-on-content-updated',
            data: [
                'code' => 'seo-analysis-on-content-updated',
                'name' => 'SEO analysis on content updated',
                'description' => 'When article content updates, run SEO analysis. DISABLED by default.',
                'event_name' => BusinessEventName::ArticleContentUpdated->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleRunSeoAnalysis->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'force' => true,
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'publish-generated-product-reviews-to-wordpress',
            data: [
                'code' => 'publish-generated-product-reviews-to-wordpress',
                'name' => 'Publish generated product reviews to WordPress',
                'description' => 'On article.product_reviews_generated: schedule each review with max_delay_time. DISABLED by default.',
                'event_name' => BusinessEventName::ArticleProductReviewsGenerated->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => false,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleProductReviewsScheduleGenerated->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'review_ids' => '{{ payload.review_ids }}',
                    ],
                    'settings' => [
                        'max_delay_time' => 5,
                    ],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'publish-pending-product-reviews-after-article-sync',
            data: [
                'code' => 'publish-pending-product-reviews-after-article-sync',
                'name' => 'Queue pending product reviews after article sync',
                'description' => 'After wordpress.synced: reconcile all pending reviews and schedule publish. DISABLED by default.',
                'event_name' => BusinessEventName::WordpressSynced->value,
                'is_enabled' => false,
                'priority' => 120,
                'stop_on_failure' => false,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleProductReviewsQueuePending->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => [
                        'max_delay_time' => 5,
                    ],
                ],
            ],
        );

        // Infrastructure: delayed job emits publish_requested → this rule runs WP publish in-process (sync).
        $this->seedIfMissing(
            code: 'execute-wordpress-comment-review-publish',
            data: [
                'code' => 'execute-wordpress-comment-review-publish',
                'name' => 'Execute WordPress comment review publish',
                'description' => 'Internal: run wordpress.comment_review.publish after schedule delay. Keep enabled with product-review rules. Prefer sync so delayed job finishes publish without extra queue hop.',
                'event_name' => BusinessEventName::ArticleProductReviewPublishRequested->value,
                'is_enabled' => true,
                'priority' => 50,
                'stop_on_failure' => false,
                'run_mode' => 'sync',
                'trigger_type' => AutomationTriggerType::Event->value,
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::WordpressCommentReviewPublish->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'site_id' => '{{ payload.site_id }}',
                        'connection_id' => '{{ payload.connection_id }}',
                        'article_id' => '{{ payload.article_id }}',
                        'review_id' => '{{ payload.review_id }}',
                        'wp_post_id' => '{{ payload.wp_post_id }}',
                        'publish_intent' => '{{ payload.publish_intent }}',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->migrateProductReviewAutomationRules();

        $this->seedIfMissing(
            code: 'notify-on-notification-requested',
            data: [
                'code' => 'notify-on-notification-requested',
                'name' => 'Deliver notification.requested',
                'description' => 'Deliver in-app notification when notification.requested emitted. DISABLED by default.',
                'event_name' => BusinessEventName::NotificationRequested->value,
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
                        'message' => '{{ payload.message }}',
                        'title' => '{{ payload.title }}',
                        'user_id' => '{{ payload.user_id }}',
                        'project_id' => '{{ payload.project_id }}',
                    ],
                    'settings' => [],
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
     * Migrate existing product-review rules to schedule/reconcile + max_delay_time + internal publish rule.
     */
    private function migrateProductReviewAutomationRules(): void
    {
        $generated = AutomationRule::query()
            ->where('code', 'publish-generated-product-reviews-to-wordpress')
            ->first();
        if ($generated instanceof AutomationRule) {
            $generated->forceFill([
                'event_name' => BusinessEventName::ArticleProductReviewsGenerated->value,
                'description' => 'On article.product_reviews_generated: schedule each review with max_delay_time.',
            ])->save();
            $generated->loadMissing('actions');
            $scheduleAction = $generated->actions->first(
                static fn ($a): bool => (string) $a->action_code === AutomationActionCode::ArticleProductReviewsScheduleGenerated->value
            );
            if ($scheduleAction === null) {
                foreach ($generated->actions as $old) {
                    $old->delete();
                }
                $generated->actions()->create([
                    'action_code' => AutomationActionCode::ArticleProductReviewsScheduleGenerated->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'review_ids' => '{{ payload.review_ids }}',
                    ],
                    'settings' => ['max_delay_time' => 5],
                ]);
            } else {
                $settings = is_array($scheduleAction->settings) ? $scheduleAction->settings : [];
                $settings = \App\Addons\SeoContentAi\Services\ProductReview\ProductReviewDelaySettings::normalizeSettings($settings);
                $scheduleAction->forceFill([
                    'settings' => $settings,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'review_ids' => '{{ payload.review_ids }}',
                    ],
                ])->save();
            }
        }

        $pending = AutomationRule::query()
            ->where('code', 'publish-pending-product-reviews-after-article-sync')
            ->first();
        if ($pending instanceof AutomationRule) {
            $pending->loadMissing('actions');
            foreach ($pending->actions as $action) {
                if ((string) $action->action_code !== AutomationActionCode::ArticleProductReviewsQueuePending->value) {
                    continue;
                }
                $settings = is_array($action->settings) ? $action->settings : [];
                $settings = \App\Addons\SeoContentAi\Services\ProductReview\ProductReviewDelaySettings::normalizeSettings($settings);
                $action->forceFill(['settings' => $settings])->save();
            }
        }

        $executeActions = [
            [
                'action_code' => AutomationActionCode::WordpressCommentReviewPublish->value,
                'position' => 0,
                'is_enabled' => true,
                'continue_on_failure' => true,
                'delay_seconds' => 0,
                'input_mapping' => [
                    'site_id' => '{{ payload.site_id }}',
                    'connection_id' => '{{ payload.connection_id }}',
                    'article_id' => '{{ payload.article_id }}',
                    'review_id' => '{{ payload.review_id }}',
                    'wp_post_id' => '{{ payload.wp_post_id }}',
                    'publish_intent' => '{{ payload.publish_intent }}',
                ],
                'settings' => [],
            ],
        ];

        $execute = AutomationRule::query()
            ->where('code', 'execute-wordpress-comment-review-publish')
            ->first();
        if (! $execute instanceof AutomationRule) {
            $this->ruleService->createRule([
                'code' => 'execute-wordpress-comment-review-publish',
                'name' => 'Execute WordPress comment review publish',
                'description' => 'Internal: run wordpress.comment_review.publish after schedule delay.',
                'event_name' => BusinessEventName::ArticleProductReviewPublishRequested->value,
                'is_enabled' => true,
                'priority' => 50,
                'stop_on_failure' => false,
                'run_mode' => 'sync',
                'trigger_type' => AutomationTriggerType::Event->value,
                'conditions' => null,
            ], $executeActions);

            return;
        }

        $execute->forceFill([
            'is_enabled' => true,
            'event_name' => BusinessEventName::ArticleProductReviewPublishRequested->value,
            'run_mode' => 'sync',
            'trigger_type' => AutomationTriggerType::Event->value,
        ])->save();

        $execute->loadMissing('actions');
        $publishAction = $execute->actions->first(
            static fn ($a): bool => (string) $a->action_code === AutomationActionCode::WordpressCommentReviewPublish->value
        );
        if ($publishAction === null) {
            foreach ($execute->actions as $old) {
                $old->delete();
            }
            $execute->actions()->create($executeActions[0]);
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
