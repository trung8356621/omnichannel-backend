<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepCatalogService;
use App\Addons\SeoContentAi\Services\SeoProjectWorkflowStepRetryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SeoProjectWorkflowStepRetryServiceTest extends TestCase
{
    public function test_step_action_stays_within_column_limit(): void
    {
        $service = $this->newServiceWithoutConstructor();
        $short = $service->stepAction('prompt-outline');
        self::assertSame('step:prompt-outline', $short);
        self::assertLessThanOrEqual(64, strlen($short));

        $longNode = str_repeat('n', 80);
        $hashed = $service->stepAction($longNode);
        self::assertStringStartsWith('step:', $hashed);
        self::assertLessThanOrEqual(64, strlen($hashed));
        self::assertNotSame('step:'.$longNode, $hashed);
    }

    public function test_catalog_orders_outline_before_content(): void
    {
        $catalog = $this->getMockBuilder(SeoProjectWorkflowStepCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listRerunnableSteps'])
            ->getMock();

        $task = new \App\Addons\SeoContentAi\Models\SeoProjectTask;
        $task->id = 1;

        $catalog->method('listRerunnableSteps')->willReturn([
            [
                'node_id' => 'content-1',
                'title' => 'Content',
                'label' => 'Viết lại nội dung',
                'kind' => 'content',
                'prompt_id' => 2,
                'depends_on_kinds' => ['outline'],
            ],
            [
                'node_id' => 'outline-1',
                'title' => 'Outline',
                'label' => 'Tạo lại outline',
                'kind' => 'outline',
                'prompt_id' => 1,
                'depends_on_kinds' => [],
            ],
            [
                'node_id' => 'image-1',
                'title' => 'Image',
                'label' => 'Tạo lại ảnh',
                'kind' => 'image',
                'prompt_id' => 3,
                'depends_on_kinds' => [],
            ],
        ]);

        $ordered = $catalog->orderNodeIdsByDependency($task, ['image-1', 'content-1', 'outline-1']);
        self::assertSame(['outline-1', 'content-1', 'image-1'], $ordered);
    }

    public function test_view_run_disables_rerun_all_entry(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString("public function canRerunAllItems(): bool\n    {\n        return false;", $source);
        self::assertStringContainsString('retryWorkflowStep', $source);
        self::assertStringContainsString('bulkRetryWorkflowSteps', $source);
        self::assertStringContainsString('getBulkWorkflowSteps', $source);
    }

    public function test_blade_removed_rerun_all_button(): void
    {
        $blade = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php'
        );
        self::assertNotFalse($blade);
        self::assertStringNotContainsString('canRerunAllItems()', $blade);
        self::assertStringNotContainsString('openRerunSettingsModal()', $blade);
        self::assertStringContainsString('retryWorkflowStep', $blade);
        self::assertStringContainsString('selectedTaskIds', $blade);
        self::assertStringContainsString('run_item_last_saved', $blade);
    }

    private function newServiceWithoutConstructor(): SeoProjectWorkflowStepRetryService
    {
        $ref = new ReflectionClass(SeoProjectWorkflowStepRetryService::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
