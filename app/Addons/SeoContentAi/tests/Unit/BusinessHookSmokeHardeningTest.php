<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationExecution;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\BusinessEvent;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationExecutionService;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\BusinessEventDispatcher;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Business Hook smoke hardening — requires omi_seo_ai migrated + seeded rules.
 */
final class BusinessHookSmokeHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            BusinessEvent::query()->limit(1)->get();
        } catch (\Throwable) {
            $this->markTestSkipped('business_events table not available — run SEO migrations first.');
        }
    }

    public function test_case_a_disabled_wordpress_rule_does_not_queue_sync(): void
    {
        Queue::fake();

        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule sync-article-to-wordpress missing — run automation:seed-rules.');
        }

        $this->assertFalse((bool) $rule->is_enabled);

        $uuid = 'hardening-a-'.uniqid('', true);
        app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: [
                'article_id' => 999001,
                'site_id' => 1,
                'status' => 'completed',
            ],
            context: ['site_id' => 1],
            eventUuid: $uuid,
        );

        Queue::assertNotPushed(ExecuteAutomationRuleJob::class);

        $event = BusinessEvent::query()->where('event_uuid', $uuid)->first();
        $this->assertNotNull($event);
        $this->assertSame(0, AutomationExecution::query()->where('business_event_id', $event->id)->count());
    }

    public function test_case_b_enabled_rule_missing_article_fails_without_wp_sync_call(): void
    {
        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule missing.');
        }

        $originalEnabled = (bool) $rule->is_enabled;
        $rule->forceFill(['is_enabled' => true])->save();

        $mock = $this->mock(WordPressArticleSyncService::class);
        $mock->shouldNotReceive('syncForArticle');
        $mock->shouldNotReceive('publishForArticle');

        try {
            $uuid = 'hardening-b-'.uniqid('', true);
            $event = app(BusinessEventDispatcher::class)->dispatch(
                BusinessEventName::ArticleCompleted->value,
                payload: [
                    'article_id' => 999888777,
                    'site_id' => 1,
                    'status' => 'completed',
                ],
                context: ['site_id' => 1],
                eventUuid: $uuid,
            );

            $execution = AutomationExecution::query()
                ->where('business_event_id', $event->id)
                ->where('automation_rule_id', $rule->id)
                ->first();

            $this->assertNotNull($execution);

            $ran = app(AutomationExecutionService::class)->run((int) $execution->id);
            $ran->refresh();

            $this->assertContains($ran->status, [
                AutomationExecutionStatus::Failed->value,
                AutomationExecutionStatus::Partial->value,
            ]);
            $this->assertNotNull($ran->error_code);
        } finally {
            $rule->forceFill(['is_enabled' => $originalEnabled])->save();
        }
    }

    public function test_case_c_duplicate_event_uuid_creates_single_business_event(): void
    {
        $uuid = 'hardening-c-'.uniqid('', true);
        $first = app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: ['article_id' => 2, 'site_id' => 1],
            eventUuid: $uuid,
        );
        $second = app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: ['article_id' => 2, 'site_id' => 1],
            eventUuid: $uuid,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BusinessEvent::query()->where('event_uuid', $uuid)->count());
    }

    public function test_case_d_wp_sync_failure_sets_failed_status_and_error_code(): void
    {
        $article = SeoArticle::query()->first();
        if (! $article instanceof SeoArticle) {
            $this->markTestSkipped('No SeoArticle available for WP sync failure scenario.');
        }

        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule missing.');
        }

        $originalEnabled = (bool) $rule->is_enabled;
        $rule->forceFill(['is_enabled' => true])->save();

        $mock = $this->mock(WordPressArticleSyncService::class);
        $mock->shouldReceive('syncForArticle')
            ->once()
            ->andReturn(['success' => false, 'message' => 'WP mock failure']);

        try {
            $uuid = 'hardening-d-'.uniqid('', true);
            $event = app(BusinessEventDispatcher::class)->dispatch(
                BusinessEventName::ArticleCompleted->value,
                $article,
                [
                    'article_id' => (int) $article->getKey(),
                    'site_id' => (int) ($article->site_id ?? 1),
                    'status' => 'completed',
                ],
                ['site_id' => (int) ($article->site_id ?? 1)],
                $uuid,
            );

            $execution = AutomationExecution::query()
                ->where('business_event_id', $event->id)
                ->where('automation_rule_id', $rule->id)
                ->first();

            $this->assertNotNull($execution);

            $ran = app(AutomationExecutionService::class)->run((int) $execution->id);
            $ran->refresh();

            $this->assertContains($ran->status, [
                AutomationExecutionStatus::Failed->value,
                AutomationExecutionStatus::Partial->value,
            ]);
            $this->assertNotNull($ran->error_code);
        } finally {
            $rule->forceFill(['is_enabled' => $originalEnabled])->save();
        }
    }

    public function test_case_e_production_callers_do_not_reference_wp_sync_services(): void
    {
        $paths = [
            dirname(__DIR__, 2).'/Services/CreateArticlesFromTaskService.php',
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowRunService.php',
            dirname(__DIR__, 2).'/Services/ArticleScheduleReconcileService.php',
        ];

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('WordPressArticleSyncService', $source);
            self::assertStringNotContainsString('ArticleWpSyncQueueService', $source);
        }
    }

    public function test_wordpress_hook_action_class_is_wired(): void
    {
        $this->assertTrue(is_a(
            SyncArticleToWordPressHookAction::class,
            \App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler::class,
            true,
        ));
        $this->assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
    }

    public function test_enabled_rule_creates_execution_and_queues_job(): void
    {
        Queue::fake();

        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule missing.');
        }

        $originalEnabled = (bool) $rule->is_enabled;
        $rule->forceFill(['is_enabled' => true])->save();

        try {
            $uuid = 'hardening-queue-'.uniqid('', true);
            $event = app(BusinessEventDispatcher::class)->dispatch(
                BusinessEventName::ArticleCompleted->value,
                payload: [
                    'article_id' => 1,
                    'site_id' => 1,
                    'status' => 'completed',
                ],
                context: ['site_id' => 1],
                eventUuid: $uuid,
            );

            $execution = AutomationExecution::query()
                ->where('business_event_id', $event->id)
                ->where('automation_rule_id', $rule->id)
                ->first();

            $this->assertNotNull($execution);
            $this->assertSame(AutomationExecutionStatus::Pending->value, $execution->status);
            Queue::assertPushed(ExecuteAutomationRuleJob::class, function (ExecuteAutomationRuleJob $job) use ($execution): bool {
                return $job->automationExecutionId === (int) $execution->id
                    && $job->queue === \App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName::Critical->value;
            });
        } finally {
            $rule->forceFill(['is_enabled' => $originalEnabled])->save();
        }
    }

    public function test_missing_article_action_returns_subject_not_found_error_code(): void
    {
        $rule = new AutomationRule(['code' => 'inline-test']);
        $execution = new AutomationExecution(['id' => 1, 'idempotency_key' => 'k', 'context' => []]);
        $event = new BusinessEvent([
            'event_name' => BusinessEventName::ArticleCompleted->value,
            'payload' => ['article_id' => 999777],
        ]);

        $context = new \App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext(
            businessEvent: $event,
            rule: $rule,
            execution: $execution,
            subject: null,
            subjectData: [],
            siteId: 1,
            projectId: null,
            actorId: null,
            correlationId: null,
            automationDepth: 0,
        );

        $mock = $this->mock(WordPressArticleSyncService::class);
        $mock->shouldNotReceive('syncForArticle');
        $mock->shouldNotReceive('publishForArticle');

        $action = app(SyncArticleToWordPressHookAction::class);
        $result = $action->handle($context, ['article_id' => 999777], []);

        self::assertFalse($result->success);
        self::assertSame(BusinessHookErrorCode::SubjectNotFound->value, $result->errorCode);
    }
}
