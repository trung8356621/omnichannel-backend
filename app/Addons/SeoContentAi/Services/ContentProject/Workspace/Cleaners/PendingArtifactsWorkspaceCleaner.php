<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners;

use App\Addons\SeoContentAi\Enums\ArticleProductReviewStatus;
use App\Addons\SeoContentAi\Models\ArticleProductReview;
use App\Addons\SeoContentAi\Models\SeoPendingInternalLink;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn pending artifacts chỉ phục vụ AI workflow (link gợi ý, product review local pending).
 */
final class PendingArtifactsWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'pending_artifacts';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $articleIds = $context->articleIds();

        if (Schema::connection('omi_seo_ai')->hasTable('seo_pending_internal_links')) {
            $deletedLinks = SeoPendingInternalLink::query()
                ->whereIn('source_article_id', $articleIds)
                ->where(function ($query): void {
                    $query->whereNull('status')
                        ->orWhere('status', 'pending')
                        ->orWhere('status', 'suggested');
                })
                ->delete();
            $context->bumpStat('pending_internal_links_deleted', (int) $deletedLinks);
        }

        $deletedLocal = ArticleProductReview::query()
            ->whereIn('article_id', $articleIds)
            ->whereIn('status', [
                ArticleProductReviewStatus::Pending->value,
                ArticleProductReviewStatus::Draft->value,
                ArticleProductReviewStatus::PendingArticle->value,
                ArticleProductReviewStatus::Failed->value,
                ArticleProductReviewStatus::Syncing->value,
            ])
            ->delete();
        $context->bumpStat('pending_product_reviews_deleted', (int) $deletedLocal);
    }
}
