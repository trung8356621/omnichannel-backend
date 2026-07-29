<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ContentProjectPublishingQueue;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages\ViewSeoProjectRun;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectItemOperationsReadModel;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectItemActionsPresenter;
use App\Addons\SeoContentAi\Support\ContentProject\ContentProjectStatusBadgePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class ContentProjectOperationsUiCutoverTest extends TestCase
{
    public function test_view_project_is_canonical_items_table(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('view-seo-project-operations', $src);
        self::assertStringContainsString('ContentProjectItemOperationsReadModel', $src);
        self::assertStringContainsString('InteractsWithContentProjectPublishingActions', $src);
        self::assertStringContainsString('getHeading', $src);
        self::assertStringContainsString('getSubheading', $src);
        self::assertStringContainsString('ActionGroup::make', $src);
        self::assertStringNotContainsString('extends EditSeoProject', $src);
        self::assertStringNotContainsString("Action::make('publishing_queue')", $src);
    }

    public function test_operations_blade_kpi_grid_and_toolbar(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );

        self::assertStringContainsString('cp-ops-kpi-grid', $blade);
        self::assertStringContainsString('content-project-summary-card', $blade);
        self::assertStringContainsString('content-project-filter-toolbar', $blade);
        self::assertStringContainsString('content-project-bulk-selection-toolbar', $blade);
        self::assertStringContainsString('content-project-status-badge', $blade);
        self::assertStringContainsString('content-project-item-actions-menu', $blade);
        self::assertStringContainsString('content-project-item-meta', $blade);
        self::assertStringContainsString('applySummaryFilter', $blade);
        self::assertStringContainsString('cp-ops-table', $blade);
        self::assertStringContainsString('cp-ops-toolbar', $blade);
        self::assertStringContainsString('cp-ops-mobile-list', $blade);
        self::assertStringContainsString('No items match filters', $blade);
        self::assertStringNotContainsString('run_item_run_at', $blade);
        self::assertStringNotContainsString('seo-run-items-wrap', $blade);
        self::assertStringNotContainsString('<h2 class="truncate text-lg', $blade);
    }

    public function test_bulk_toolbar_only_when_selected(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/components/content-project-bulk-selection-toolbar.blade.php',
        );
        self::assertStringContainsString('selectedCount > 0', $blade);
        self::assertStringContainsString('Bulk selection actions', $blade);
        self::assertStringContainsString('Content', $blade);
        self::assertStringContainsString('Review', $blade);
        self::assertStringContainsString('Publishing', $blade);
    }

    public function test_actions_menu_groups_and_gates(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/components/content-project-item-actions-menu.blade.php',
        );
        self::assertStringContainsString('ContentProjectItemActionsPresenter', $blade);
        self::assertStringContainsString('max-h-72', $blade);
        self::assertStringContainsString('>Content</p>', $blade);
        self::assertStringContainsString('>Review</p>', $blade);
        self::assertStringContainsString('>Publishing</p>', $blade);
        self::assertStringContainsString('>Other</p>', $blade);

        $reviewOnly = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'review',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'success'],
            'can_generate' => false,
            'can_regen' => true,
            'article_edit_url' => '/a/1',
            'is_scheduled' => false,
        ]);
        self::assertTrue($reviewOnly['approve']);
        self::assertFalse($reviewOnly['start_review']);
        self::assertFalse($reviewOnly['generate']);
        self::assertTrue($reviewOnly['has_review']);

        $pending = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'draft',
            'queue_status' => 'none',
            'generation_badge' => ['key' => 'pending'],
            'can_generate' => true,
            'can_regen' => false,
            'article_edit_url' => null,
            'is_scheduled' => false,
        ]);
        self::assertTrue($pending['generate']);
        self::assertFalse($pending['approve']);
        self::assertFalse($pending['publish_now']);
    }

    public function test_status_badge_semantic_colors(): void
    {
        $gen = ContentProjectStatusBadgePresenter::generation('writing');
        self::assertSame('running', $gen['key']);
        self::assertStringContainsString('bg-info-', $gen['classes']);
        self::assertArrayHasKey('icon', $gen);

        $fail = ContentProjectStatusBadgePresenter::generation('failed');
        self::assertSame('failed', $fail['key']);
        self::assertStringContainsString('bg-danger-', $fail['classes']);

        $review = ContentProjectStatusBadgePresenter::lifecycle('review');
        self::assertSame('review', $review['key']);
        self::assertStringContainsString('bg-warning-', $review['classes']);

        $queue = ContentProjectStatusBadgePresenter::queue('waiting');
        self::assertSame('waiting', $queue['key']);

        $accent = ContentProjectStatusBadgePresenter::summaryAccent('failed');
        self::assertSame('failed', $accent['key']);
        self::assertStringContainsString('border-l-danger', $accent['ring']);
    }

    public function test_read_model_keeps_three_status_axes(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('generation_status', $src);
        self::assertStringContainsString("'lifecycle'", $src);
        self::assertStringContainsString('queue_status', $src);
        self::assertStringContainsString('applyFilters', $src);
        self::assertStringContainsString('TYPE_IMPROVE', $src);
    }

    public function test_publishing_queue_route_redirects_not_404(): void
    {
        $pages = SeoProjectResource::getPages();
        self::assertArrayHasKey('publishing-queue', $pages);

        $src = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueue::class))->getFileName(),
        );
        self::assertStringContainsString('redirect', $src);
        self::assertStringContainsString('waiting_publish,published', $src);
        self::assertStringContainsString('redirect-placeholder', $src);

        $prop = new ReflectionProperty(ContentProjectPublishingQueue::class, 'record');
        $typeName = (string) $prop->getType();
        self::assertTrue(in_array($typeName, ['int|string', 'string|int'], true));

        $resourceSrc = (string) file_get_contents(
            (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString("lifecycle' => 'waiting_publish,published'", $resourceSrc);
    }

    public function test_run_history_remains_redirect_only(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProjectRun::class))->getFileName(),
        );
        self::assertStringContainsString('redirect', strtolower($src));
        self::assertStringContainsString('getProjectWorkspaceUrl', $src);
    }

    public function test_test_run_hidden_production_ui(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString("environment('production')", $src);
        self::assertStringContainsString('allowsDevTestGenerateUi', $src);

        $viewSrc = (string) file_get_contents(
            (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        self::assertStringContainsString('allowsDevTestGenerateUi', $viewSrc);
        self::assertStringContainsString('ActionGroup::make', $viewSrc);
    }

    public function test_publishing_actions_reuse_command_bus_trait(): void
    {
        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/Filament/Resources/SeoProjectResource/Concerns/InteractsWithContentProjectPublishingActions.php',
        );
        self::assertStringContainsString('ContentProjectCommandBus', $trait);
        self::assertStringContainsString('PublishProjectItemsNowCommand', $trait);
        self::assertStringContainsString('AutoScheduleProjectItemsCommand', $trait);
        self::assertStringNotContainsString('ContentPublisher', $trait);
    }

    public function test_reusable_ui_components_exist(): void
    {
        $base = dirname(__DIR__, 2).'/resources/views/components';
        foreach ([
            'content-project-summary-card.blade.php',
            'content-project-status-badge.blade.php',
            'content-project-filter-toolbar.blade.php',
            'content-project-item-actions-menu.blade.php',
            'content-project-item-meta.blade.php',
            'content-project-bulk-selection-toolbar.blade.php',
        ] as $file) {
            self::assertFileExists($base.'/'.$file);
        }
    }
}
