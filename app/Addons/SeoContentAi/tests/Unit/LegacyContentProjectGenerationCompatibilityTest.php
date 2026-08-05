<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Console\RepairLegacyContentProjectGenerationCommand;
use App\Addons\SeoContentAi\Services\ContentProject\LegacyContentProjectItemHydrator;
use PHPUnit\Framework\TestCase;

final class LegacyContentProjectGenerationCompatibilityTest extends TestCase
{
    public function test_full_workflow_uses_clean_restart_context(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/Services/SeoProjectWorkflowRunService.php');

        self::assertStringContainsString('cleanRestart:', $source);
        self::assertStringContainsString('$fromStep === null', $source);
    }

    public function test_clean_restart_rewrite_does_not_require_existing_outline_before_workflow_runs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/Services/TaskTestInputResolver.php');
        $start = strpos($source, 'private function resolveExistingArticleRewriteForCleanRestart');
        self::assertNotFalse($start);
        $end = strpos($source, 'private function stampProjectTaskOrigin', (int) $start);
        self::assertNotFalse($end);
        $chunk = substr($source, (int) $start, (int) $end - (int) $start);

        self::assertStringNotContainsString('applyOutlineFromArticle', $chunk);
        self::assertStringContainsString("'rerun_scope'] = 'full'", $chunk);
        self::assertStringContainsString("'force_ai_regenerate'] = 'true'", $chunk);
        self::assertStringContainsString("'article_writing_raw_input'", $chunk);
    }

    public function test_repair_command_and_hydrator_are_registered(): void
    {
        self::assertTrue(class_exists(RepairLegacyContentProjectGenerationCommand::class));
        self::assertTrue(class_exists(LegacyContentProjectItemHydrator::class));

        $provider = (string) file_get_contents(dirname(__DIR__, 2).'/SeoContentAiServiceProvider.php');
        self::assertStringContainsString('RepairLegacyContentProjectGenerationCommand::class', $provider);

        $command = new RepairLegacyContentProjectGenerationCommand;
        self::assertStringContainsString('seo:content-project:repair-legacy', (string) $command->getName());
    }

    public function test_hydrator_preserves_business_publish_fields_by_not_referencing_them(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/Services/ContentProject/LegacyContentProjectItemHydrator.php');

        self::assertStringNotContainsString('wp_post_id', $source);
        self::assertStringNotContainsString('publish_published_at', $source);
        self::assertStringNotContainsString('scheduled_publish_at', $source);
        self::assertStringContainsString('dry_run', $source);
    }
}
