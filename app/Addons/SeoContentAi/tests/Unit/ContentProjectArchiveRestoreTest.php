<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchive;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use App\Addons\SeoContentAi\Services\ArticleCompletedArchiveQueryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guard: trang Archive giữ UI card cũ (`archive-dashboard`) nhưng data từ
 * {@see ArticleCompletedArchiveQueryService} (không Filament Table thô, không
 * `SeoProjectArchiveService` / `seo_content_archive_items`).
 */
final class ContentProjectArchiveRestoreTest extends TestCase
{
    public function test_seo_project_resource_registers_the_archive_route_again(): void
    {
        $pages = SeoProjectResource::getPages();

        self::assertArrayHasKey('archive', $pages);
    }

    public function test_project_archives_url_points_to_the_archive_route(): void
    {
        $method = (new ReflectionClass(SeoProjectResource::class))->getMethod('projectArchivesUrl');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString("static::getUrl('archive')", $source);
    }

    public function test_list_seo_projects_restores_the_open_archive_header_action(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ListSeoProjects::class))->getFileName());

        self::assertStringContainsString("Actions\\Action::make('open_site_archive')", $source);
        self::assertStringContainsString('canViewProjectArchives', $source);
        self::assertStringContainsString("SeoProjectResource::getUrl('archive')", $source);
    }

    public function test_content_project_archive_page_uses_card_dashboard_not_filament_table(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchive::class))->getFileName());

        self::assertStringNotContainsString('InteractsWithTable', $source);
        self::assertStringNotContainsString('SeoContentArchiveItem', $source);
        self::assertStringNotContainsString('SeoProjectArchiveService', $source);
        self::assertStringContainsString('reopenArticle', $source);

        $viewPath = base_path(
            'app/Addons/SeoContentAi/resources/views/filament/resources/seo-project-resource/pages/content-project-archive.blade.php',
        );
        $view = (string) file_get_contents($viewPath);
        self::assertStringContainsString('archive-dashboard', $view);
        self::assertStringNotContainsString('$this->table', $view);

        $dashboard = (string) file_get_contents(base_path(
            'app/Addons/SeoContentAi/resources/views/filament/resources/seo-project-resource/partials/archive-dashboard.blade.php',
        ));
        self::assertStringContainsString(ArticleCompletedArchiveQueryService::class, $dashboard);
        self::assertStringContainsString('buildGroupedDashboard', $dashboard);
        self::assertStringNotContainsString('SeoProjectArchiveService', $dashboard);
        self::assertStringNotContainsString('seo_project_archives', $dashboard);
        self::assertStringNotContainsString('SeoContentArchiveItem', $dashboard);
    }

    public function test_content_project_archive_page_exposes_a_reopen_method_backed_by_article_review_service(): void
    {
        $ref = new ReflectionClass(ContentProjectArchive::class);
        self::assertTrue($ref->hasMethod('reopenArticle'));

        $method = $ref->getMethod('reopenArticle');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('ArticleReviewService', $source);
        self::assertStringContainsString('ArticleReviewActionType::Reopen', $source);
    }

    public function test_article_completed_archive_query_service_exposes_dashboard_adapter(): void
    {
        $ref = new ReflectionClass(ArticleCompletedArchiveQueryService::class);

        self::assertTrue($ref->hasMethod('queryForSites'));
        self::assertTrue($ref->hasMethod('buildGroupedDashboard'));

        $method = $ref->getMethod('buildGroupedDashboard');
        $source = $this->readMethodSource($method);
        self::assertStringContainsString('review_status', file_get_contents((string) $ref->getFileName()) ?: '');
        self::assertStringContainsString('ArticleResource::getUrl', $source);
        self::assertStringNotContainsString('SeoContentArchiveItem', $source);
        self::assertStringNotContainsString('seo_project_archives', $source);
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
