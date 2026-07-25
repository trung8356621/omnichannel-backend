<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Services\ArticlePipelineRerunStartStepResolver;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepRetryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PromptExecutionOrchestrationTest extends TestCase
{
    public function test_rerun_resolver_maps_stale_node_to_semantic_kind(): void
    {
        $publish = new SeoTask;
        $publish->forceFill([
            'id' => 10,
            'flow_data' => [
                'nodes' => [
                    ['id' => 'node_current_outline', 'type' => 'prompt', 'title' => 'Tạo dàn ý SEO'],
                    ['id' => 'node_current_content', 'type' => 'prompt', 'title' => 'Viết bài theo dàn ý'],
                ],
                'edges' => [],
            ],
        ]);

        $catalog = $this->getMockBuilder(SeoProjectWorkflowStepCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'resolveSeoTaskForStepRetry',
                'firstPromptNodeIdForKind',
                'findStep',
                'listRerunnableSteps',
            ])
            ->getMock();

        $catalog->method('resolveSeoTaskForStepRetry')->willReturn($publish);
        $catalog->method('firstPromptNodeIdForKind')->willReturnCallback(
            static function (SeoProjectTask $task, string $kind) use ($publish): ?string {
                return $kind === 'outline' ? 'node_current_outline' : 'node_current_content';
            }
        );
        $catalog->method('findStep')->willReturn(null);
        $catalog->method('listRerunnableSteps')->willReturn([
            ['node_id' => 'node_current_outline', 'kind' => 'outline', 'title' => 'Outline', 'label' => 'x', 'prompt_id' => 1, 'depends_on_kinds' => []],
            ['node_id' => 'node_current_content', 'kind' => 'content', 'title' => 'Content', 'label' => 'y', 'prompt_id' => 2, 'depends_on_kinds' => ['outline']],
        ]);

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $task = new SeoProjectTask;
        $task->id = 1;

        $resolved = $resolver->resolve($task, 'outline', 'node_1780563019334');

        self::assertTrue($resolved['ok']);
        self::assertSame('node_current_outline', $resolved['resolved_node_id']);
        self::assertSame('node_1780563019334', $resolved['source_node_id']);
        self::assertSame(ArticlePipelineRerunStartStepResolver::STRATEGY_SEMANTIC_KIND, $resolved['strategy']);
        self::assertSame('outline', $resolved['semantic_key']);
    }

    public function test_rerun_resolver_uses_direct_node_when_still_present(): void
    {
        $publish = new SeoTask;
        $publish->forceFill([
            'id' => 10,
            'flow_data' => [
                'nodes' => [
                    ['id' => 'node_1780563019334', 'type' => 'prompt', 'title' => 'Tạo dàn ý SEO'],
                ],
                'edges' => [],
            ],
        ]);

        $catalog = $this->getMockBuilder(SeoProjectWorkflowStepCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'resolveSeoTaskForStepRetry',
                'firstPromptNodeIdForKind',
                'findStep',
                'listRerunnableSteps',
            ])
            ->getMock();

        $catalog->method('resolveSeoTaskForStepRetry')->willReturn($publish);
        $catalog->method('findStep')->willReturn([
            'node_id' => 'node_1780563019334',
            'kind' => 'outline',
            'title' => 'Outline',
            'label' => 'x',
            'prompt_id' => 1,
            'depends_on_kinds' => [],
        ]);
        $catalog->method('listRerunnableSteps')->willReturn([
            ['node_id' => 'node_1780563019334', 'kind' => 'outline', 'title' => 'Outline', 'label' => 'x', 'prompt_id' => 1, 'depends_on_kinds' => []],
        ]);

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $resolved = $resolver->resolve(new SeoProjectTask, 'outline', 'node_1780563019334');

        self::assertTrue($resolved['ok']);
        self::assertSame('node_1780563019334', $resolved['resolved_node_id']);
        self::assertSame(ArticlePipelineRerunStartStepResolver::STRATEGY_DIRECT_NODE, $resolved['strategy']);
    }

    public function test_rerun_resolver_unresolved_has_user_message_not_raw_node(): void
    {
        $catalog = $this->getMockBuilder(SeoProjectWorkflowStepCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'resolveSeoTaskForStepRetry',
                'firstPromptNodeIdForKind',
                'findStep',
                'listRerunnableSteps',
            ])
            ->getMock();

        $empty = new SeoTask;
        $empty->forceFill(['id' => 1, 'flow_data' => ['nodes' => [], 'edges' => []]]);
        $catalog->method('resolveSeoTaskForStepRetry')->willReturn($empty);
        $catalog->method('firstPromptNodeIdForKind')->willReturn(null);
        $catalog->method('listRerunnableSteps')->willReturn([]);

        $resolver = new ArticlePipelineRerunStartStepResolver($catalog);
        $resolved = $resolver->resolve(new SeoProjectTask, 'article', 'node_1780563019334');

        self::assertFalse($resolved['ok']);
        self::assertStringContainsString('đã thay đổi', (string) $resolved['message']);
        self::assertStringNotContainsString('node_1780563019334', (string) $resolved['message']);
    }

    public function test_job_aligns_start_step_resolver_not_raw_resolve_seo_task_alone(): void
    {
        $job = (string) file_get_contents(dirname(__DIR__, 2).'/Jobs/RerunArticlePipelineJob.php');
        $service = (string) file_get_contents(dirname(__DIR__, 2).'/Services/ArticlePipelineRerunService.php');

        self::assertStringContainsString('ArticlePipelineRerunStartStepResolver', $job);
        self::assertStringContainsString('ArticlePipelineRerunStartStepResolver', $service);
        self::assertStringContainsString('resolution_strategy', $service);
        self::assertStringContainsString('semantic_key', $service);
        self::assertStringContainsString('seo.article_rerun.requested', $service);
        self::assertStringNotContainsString('$catalog->resolveSeoTask($task)', $job);
    }

    public function test_step_retry_has_terminal_guards_and_discard_after_provider(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowStepRetryService.php'
        );

        self::assertStringContainsString('isExecutionTerminal', $source);
        self::assertStringContainsString('assertExecutionStillActive', $source);
        self::assertStringContainsString('seo.workflow_step.output_discarded', $source);
        self::assertStringContainsString('seo.workflow_step.stale_execution_ignored', $source);
        self::assertStringContainsString('seo.workflow_step.terminal_failure', $source);
        self::assertStringContainsString('stoppedTaskIds', $source);
        self::assertStringContainsString('ensureCancelledFailureState', $source);

        $ref = new ReflectionClass(SeoProjectWorkflowStepRetryService::class);
        self::assertTrue($ref->hasMethod('isExecutionTerminal'));
        self::assertTrue($ref->hasMethod('assertExecutionStillActive'));
    }

    public function test_fail_prepared_is_conditional_on_active_status(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SeoProjectWorkflowStepRetryService.php'
        );
        $failPos = strpos($source, 'private function failPrepared');
        self::assertNotFalse($failPos);
        $chunk = substr($source, (int) $failPos, 1800);
        self::assertStringContainsString('whereIn(\'status\', self::ACTIVE_STATUSES)', $chunk);
        self::assertStringContainsString('ensureCancelledFailureState', $chunk);
    }

    public function test_is_execution_terminal_treats_cancel_marker_as_terminal(): void
    {
        $service = (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'isExecutionTerminal');
        $method->setAccessible(true);

        $item = new \App\Addons\SeoContentAi\Models\SeoProjectRunItem;
        $item->forceFill([
            'status' => 'processing',
            'error_message' => 'Cancelled by user.',
        ]);

        self::assertTrue($method->invoke($service, $item));
    }
}
