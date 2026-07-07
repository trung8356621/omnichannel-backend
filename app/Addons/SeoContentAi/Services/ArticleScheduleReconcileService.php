<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class ArticleScheduleReconcileService
{
    public function __construct(
        private readonly WordPressArticleSyncService $wordPressSync,
    ) {}

    /**
     * Đồng bộ trạng thái lên lịch khi mở editor: đăng bài quá hạn hoặc cập nhật Laravel nếu chưa có WP post.
     */
    public function reconcileForEditor(SeoArticle $article): bool
    {
        $article->refresh();

        if ((string) ($article->status ?? '') !== 'scheduled') {
            return false;
        }

        if (! $article->published_at instanceof Carbon || $article->published_at->isFuture()) {
            return false;
        }

        $wpPostId = (int) ($article->wp_post_id ?? 0);
        if ($wpPostId > 0) {
            $result = $this->wordPressSync->publishScheduledArticle($article->fresh());
            $article->refresh();

            if (! ($result['success'] ?? false)) {
                Log::info('Article schedule reconcile on editor load: WP publish pending retry.', [
                    'article_id' => (int) $article->id,
                    'message' => (string) ($result['message'] ?? ''),
                ]);
            }

            return (bool) ($result['success'] ?? false);
        }

        $article->update(['status' => 'published']);
        $article->refresh();

        return true;
    }

    public function shouldShowScheduleLabel(string $status): bool
    {
        return $status === 'scheduled';
    }

    public function shouldShowPublishedAtLabel(string $status, ?Carbon $publishedAt): bool
    {
        return $status === 'published' && $publishedAt instanceof Carbon;
    }
}
