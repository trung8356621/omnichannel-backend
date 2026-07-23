<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use App\Addons\SeoContentAi\Services\ArticleReviewService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskLifecycleService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskMoveService;
use App\Addons\SeoContentAi\Console\RepairArchivedArticleActiveTasksCommand;
use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

/**
 * Guard test cho fix "Content Project detach on article archive": khi
 * `ArticleReviewService::performAction(archive)` chuyển bài viết sang archived, mọi
 * `seo_project_tasks` active còn trỏ tới article đó phải bị archive theo (không còn bị Project
 * "Total items" / delete đếm nhầm — xem `SeoProjectTaskMoveService::deleteProject()`).
 *
 * Dùng reflection + source-string assertion (không cần DB thật), nhất quán với
 * `SeoProjectDeleteNoMonthRollbackTest`.
 */
final class ArticleReviewArchiveDetachesTaskTest extends TestCase
{
    public function test_article_review_service_depends_on_task_lifecycle_service(): void
    {
        $ctor = (new ReflectionClass(ArticleReviewService::class))->getConstructor();

        self::assertNotNull($ctor);
        self::assertSame(
            SeoProjectTaskLifecycleService::class,
            $ctor->getParameters()[0]->getType()?->getName(),
        );
    }

    public function test_archive_side_effect_archives_active_tasks_scoped_by_article_id(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('archiveAndDetachProjectTasks');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('article_id', $source);
        self::assertStringContainsString('->active()', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString('$this->taskLifecycle->archive(', $source);
    }

    public function test_reopen_side_effect_restores_archived_tasks_scoped_by_article_id(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('reopenAndRestoreProjectTasks');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('article_id', $source);
        self::assertStringContainsString('->archived()', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString('$this->taskLifecycle->restore(', $source);
    }

    public function test_apply_side_effects_routes_archive_and_reopen_through_task_sync_methods(): void
    {
        $method = (new ReflectionClass(ArticleReviewService::class))->getMethod('applySideEffects');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('archiveAndDetachProjectTasks', $source);
        self::assertStringContainsString('reopenAndRestoreProjectTasks', $source);
    }

    public function test_last_side_effect_meta_is_exposed_and_included_in_api_payload(): void
    {
        $ref = new ReflectionClass(ArticleReviewService::class);
        self::assertTrue($ref->hasMethod('lastSideEffectMeta'));

        $method = $ref->getMethod('toApiPayload');
        $source = $this->readMethodSource($method);
        self::assertStringContainsString('lastSideEffectMeta', $source);
        self::assertStringContainsString('content_project', $source);
    }

    public function test_repair_command_has_dry_run_and_apply_options_and_is_registered(): void
    {
        $ref = new ReflectionClass(RepairArchivedArticleActiveTasksCommand::class);
        $signature = (string) $ref->getDefaultProperties()['signature'];

        self::assertStringContainsString('seo:repair-archived-article-active-tasks', $signature);
        self::assertStringContainsString('--dry-run', $signature);
        self::assertStringContainsString('--apply', $signature);

        $providerSource = (string) file_get_contents(
            (new ReflectionClass(SeoContentAiServiceProvider::class))->getFileName(),
        );
        self::assertStringContainsString(
            RepairArchivedArticleActiveTasksCommand::class.'::class',
            $providerSource,
        );
    }

    public function test_repair_command_archives_via_task_lifecycle_service(): void
    {
        $method = (new ReflectionClass(RepairArchivedArticleActiveTasksCommand::class))->getMethod('handle');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('$taskLifecycle->archive(', $source);
        self::assertStringContainsString('->active()', $source);
    }

    /**
     * Regression: deleteProject() vẫn phải chỉ nhìn task active (không đếm task đã archive) —
     * fix này không được thay đổi hành vi đó.
     */
    public function test_delete_project_still_scopes_by_active_tasks(): void
    {
        $method = (new ReflectionClass(SeoProjectTaskMoveService::class))->getMethod('deleteProject');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('->active()', $source);
        self::assertStringNotContainsString('->tasks()->get()', $source);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
