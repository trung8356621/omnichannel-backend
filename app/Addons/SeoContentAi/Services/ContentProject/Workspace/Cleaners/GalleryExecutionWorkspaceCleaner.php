<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners;

use App\Addons\SeoContentAi\Models\SeoProductGalleryChildAttempt;
use App\Addons\SeoContentAi\Models\SeoProductGalleryExecution;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn Gallery execution / child attempt (AI workspace).
 */
final class GalleryExecutionWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'gallery_execution';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_product_gallery_executions')) {
            return;
        }

        $executionIds = SeoProductGalleryExecution::query()
            ->whereIn('article_id', $context->articleIds())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($executionIds === []) {
            return;
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_product_gallery_child_attempts')) {
            $deletedChildren = SeoProductGalleryChildAttempt::query()
                ->whereIn('parent_execution_id', $executionIds)
                ->delete();
            $context->bumpStat('gallery_child_attempts_deleted', (int) $deletedChildren);
        }

        $deletedExecutions = SeoProductGalleryExecution::query()
            ->whereIn('id', $executionIds)
            ->delete();
        $context->bumpStat('gallery_executions_deleted', (int) $deletedExecutions);
    }
}
