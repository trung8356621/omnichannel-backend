<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\PublishingQueue\PublishingQueueItemActionsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectPublishingQueueUiParityContractTest extends TestCase
{
    public function test_shared_ops_components_exist(): void
    {
        $base = dirname(__DIR__, 2).'/resources/views/components';
        foreach ([
            'content-project-ops-styles.blade.php',
            'content-project-summary-cards.blade.php',
            'content-project-items-list.blade.php',
            'content-project-filter-toolbar.blade.php',
            'content-project-bulk-selection-toolbar.blade.php',
            'publishing-queue-item-actions-menu.blade.php',
        ] as $file) {
            self::assertFileExists($base.'/'.$file, $file);
        }

        self::assertTrue(class_exists(PublishingQueueItemActionsPresenter::class));
    }

    public function test_hub_and_ops_reuse_shared_components(): void
    {
        $hub = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/pages/publishing-queue-hub.blade.php',
        );
        $ops = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );

        foreach ([
            'content-project-ops-styles',
            'content-project-summary-cards',
            'content-project-items-list',
            'content-project-filter-toolbar',
            'content-project-bulk-selection-toolbar',
        ] as $component) {
            self::assertStringContainsString($component, $hub, 'hub missing '.$component);
            self::assertStringContainsString($component, $ops, 'ops missing '.$component);
        }

        self::assertStringNotContainsString('pq-hub-kpi-grid', $hub);
        self::assertStringContainsString('variant="publishing_queue"', $hub);
        self::assertStringContainsString('variant="content_project"', $ops);
    }

    public function test_publishing_queue_read_model_exposes_thumbnail_fields(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(
                \App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueReadModel::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('thumbnail_url', $src);
        self::assertStringContainsString('primary_label', $src);
        self::assertStringContainsString('publish_badge', $src);
        self::assertStringContainsString('wp_featured_image_url', $src);
    }

    public function test_pq_presenter_gates_by_publish_state(): void
    {
        $unscheduled = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'unscheduled',
            'article_edit_url' => '/a/1',
        ]);
        self::assertTrue($unscheduled['schedule']);
        self::assertTrue($unscheduled['publish_now']);
        self::assertTrue($unscheduled['return_to_content_project']);
        self::assertFalse($unscheduled['retry_publish']);

        $failed = PublishingQueueItemActionsPresenter::forRow([
            'publish_state' => 'failed',
            'article_edit_url' => null,
        ]);
        self::assertTrue($failed['retry_publish']);
        self::assertTrue($failed['return_to_content_project']);
        self::assertFalse($failed['schedule']);
    }
}
