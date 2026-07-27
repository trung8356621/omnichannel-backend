<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Workspace\Cleaners;

use App\Addons\SeoContentAi\Models\SeoGeneratedImage;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoMediaProcessingHistory;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use App\Addons\SeoContentAi\Services\ContentProject\Workspace\Contracts\ContentProjectWorkspaceCleaner;
use Illuminate\Support\Facades\Schema;

/**
 * Dọn AI/local media chưa publish lên WordPress. Giữ media đã có wp_attachment_id.
 */
final class LocalMediaWorkspaceCleaner implements ContentProjectWorkspaceCleaner
{
    public function key(): string
    {
        return 'local_media';
    }

    public function clean(ContentProjectWorkspaceCleanupContext $context): void
    {
        if (! $context->hasArticles()) {
            return;
        }

        $articleIds = $context->articleIds();

        $localMedia = SeoMedia::query()
            ->whereIn('article_id', $articleIds)
            ->where(function ($query): void {
                $query->whereNull('wp_attachment_id')
                    ->orWhere('wp_attachment_id', '<=', 0);
            })
            ->get(['id', 'path']);

        $mediaIds = [];
        foreach ($localMedia as $media) {
            $mediaIds[] = (int) $media->id;
            $context->queueDiskPath((string) ($media->path ?? ''));
        }

        if ($mediaIds !== []) {
            $deletedHistory = SeoMediaProcessingHistory::query()
                ->whereIn('media_ref_id', $mediaIds)
                ->where('source', SeoMediaProcessingHistory::SOURCE_LOCAL)
                ->delete();
            $context->bumpStat('media_processing_histories_deleted', (int) $deletedHistory);

            $deletedMedia = SeoMedia::query()->whereIn('id', $mediaIds)->delete();
            $context->bumpStat('local_media_deleted', (int) $deletedMedia);
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_generated_images')) {
            $deletedLegacy = SeoGeneratedImage::query()
                ->whereIn('article_id', $articleIds)
                ->where(function ($query): void {
                    $query->whereNull('wp_attachment_id')
                        ->orWhere('wp_attachment_id', '<=', 0);
                })
                ->delete();
            $context->bumpStat('generated_images_deleted', (int) $deletedLegacy);
        }
    }
}
