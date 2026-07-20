<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\WordPress;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\ManualWordPressContext;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sole orchestration entry for explicit user WordPress sync.
 * Automation must use SyncArticleToWordPressHookAction instead.
 */
final class WordPressManualSyncService
{
    public function __construct(
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly WordPressArticleSyncService $syncService,
    ) {}

    public static function contextFromAuth(SeoArticle $article, string $reason): ManualWordPressContext
    {
        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('Manual WordPress sync requires authenticated user.');
        }

        return new ManualWordPressContext(
            userId: $userId,
            requestId: (string) Str::uuid(),
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            reason: $reason,
            correlationId: (string) Str::uuid(),
        );
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{success: bool, message: string, queued?: bool, queue?: array<string, mixed>, manual?: true}
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, ManualWordPressContext $context): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $this->audit($context, 'enqueue');

        $result = $this->syncQueue->enqueueFromEditorBundle($article, $bundle, $context);
        $result['manual'] = true;

        return $result;
    }

    /**
     * @return array{success: bool, message: string, queued?: bool, manual?: true}
     */
    public function resyncQueued(SeoArticle $article, ManualWordPressContext $context): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $this->audit($context, 'resync');

        $result = $this->syncQueue->resync($article, $context);
        $result['manual'] = true;

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $seoOverride
     * @return array<string, mixed>
     */
    public function publishNow(SeoArticle $article, ManualWordPressContext $context, ?array $seoOverride = null): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        $this->audit($context, 'publish_now');

        $result = $this->syncService->publishForArticle($article, $context, $seoOverride);
        $result['manual'] = true;
        $result['initiated_by'] = $context->userId;
        $result['request_id'] = $context->requestId;

        return $result;
    }

    public function syncSeoMeta(SeoArticle $article, ManualWordPressContext $context, array $seoOverride): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        $this->audit($context, 'sync_seo_meta');

        return $this->syncService->syncSeoMetaForArticle($article, $context, $seoOverride);
    }

    public function syncSlug(SeoArticle $article, ManualWordPressContext $context, string $slug): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        $this->audit($context, 'sync_slug');

        return $this->syncService->syncSlugForArticle($article, $context, $slug);
    }

    private function audit(ManualWordPressContext $context, string $action): void
    {
        Log::info('wordpress.manual_sync', [
            'manual' => true,
            'initiated_by' => $context->userId,
            'user_id' => $context->userId,
            'article_id' => $context->articleId,
            'site_id' => $context->siteId,
            'reason' => $context->reason,
            'request_id' => $context->requestId,
            'correlation_id' => $context->correlationId,
            'action' => $action,
            'source' => 'WordPressManualSyncService',
        ]);
    }
}
