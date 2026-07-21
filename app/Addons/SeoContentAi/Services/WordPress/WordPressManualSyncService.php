<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\WordPress;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\ManualAutomationDispatcher;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorBundleApplyService;
use App\Addons\SeoContentAi\Services\ArticleEditorPersistService;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Manual WordPress UI entry → ManualAutomationDispatcher only.
 */
final class WordPressManualSyncService
{
    public function __construct(
        private readonly ManualAutomationDispatcher $manualDispatcher,
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly ArticleWpSyncQueueService $syncQueue,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, User $actor, string $initiatedFrom): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless($actor->getKey() > 0, 403);

        $bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $this->persist->persistLocalSilent($article->fresh() ?? $article, $context, $html);

        $article = $article->fresh() ?? $article;
        $publishImmediately = (bool) filter_var(
            data_get($bundle, 'publish_box.publish_immediately', false),
            FILTER_VALIDATE_BOOL,
        );

        return $this->dispatchWordpress(
            $article,
            $actor,
            $initiatedFrom,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'content_version' => $this->contentVersion($article, $html),
            ],
            [
                'mode' => $publishImmediately ? 'publish' : 'sync',
                'seo_override' => $context->seoPayloadForWordPress(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resyncQueued(SeoArticle $article, User $actor, string $initiatedFrom): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->dispatchWordpress(
            $article,
            $actor,
            $initiatedFrom,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'content_version' => 'rerun:'.now()->getTimestamp(),
            ],
            ['mode' => 'sync'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $seoOverride
     * @return array<string, mixed>
     */
    public function publishNow(SeoArticle $article, User $actor, string $initiatedFrom, ?array $seoOverride = null): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->dispatchWordpress(
            $article,
            $actor,
            $initiatedFrom,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'content_version' => 'publish:'.($article->updated_at?->getTimestamp() ?? time()),
            ],
            [
                'mode' => 'publish',
                'seo_override' => $seoOverride ?? [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $seoOverride
     * @return array<string, mixed>
     */
    public function syncSeoMeta(SeoArticle $article, User $actor, string $initiatedFrom, array $seoOverride): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->dispatchWordpress(
            $article,
            $actor,
            $initiatedFrom,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'content_version' => 'seo_meta:'.md5(json_encode($seoOverride)),
            ],
            [
                'mode' => 'seo_meta',
                'seo_override' => $seoOverride,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSlug(SeoArticle $article, User $actor, string $initiatedFrom, string $slug): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->dispatchWordpress(
            $article,
            $actor,
            $initiatedFrom,
            [
                'article_id' => (int) $article->id,
                'site_id' => (int) ($article->site_id ?? 0),
                'content_version' => 'slug:'.$slug,
            ],
            [
                'mode' => 'slug',
                'slug' => $slug,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function dispatchWordpress(
        SeoArticle $article,
        User $actor,
        string $initiatedFrom,
        array $input,
        array $settings,
    ): array {
        $result = $this->manualDispatcher->dispatch(
            actionCode: AutomationActionCode::WordpressArticleSync->value,
            subject: $article,
            actor: $actor,
            input: $input,
            settings: $settings,
            context: [
                'request_id' => (string) Str::uuid(),
                'initiated_from' => $initiatedFrom,
            ],
            initiatedFrom: $initiatedFrom,
        );

        return $result->toArray();
    }

    private function contentVersion(SeoArticle $article, string $html): string
    {
        return substr(hash('sha256', ((string) ($article->updated_at?->getTimestamp() ?? 0)).'|'.$html), 0, 16);
    }
}
