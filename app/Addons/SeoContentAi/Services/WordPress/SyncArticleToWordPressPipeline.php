<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\WordPress;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\WordPressExecutionContext;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;

/**
 * Article/product WordPress sync only — no product review orchestration.
 */
final class SyncArticleToWordPressPipeline
{
    public function __construct(
        private readonly WordPressArticleSyncService $articleSync,
    ) {}

    /**
     * @param  array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null  $seoOverride
     * @return array<string, mixed>
     */
    public function run(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
        string $mode = 'sync',
        ?array $seoOverride = null,
        ?string $slug = null,
    ): array {
        $result = match ($mode) {
            'publish' => $this->articleSync->publishForArticle($article, $sideEffect, $seoOverride),
            'update_existing' => $this->articleSync->syncForArticle($article, $sideEffect, $seoOverride),
            'seo_meta' => $this->articleSync->syncSeoMetaForArticle($article, $sideEffect, $seoOverride ?? []),
            'slug' => $this->articleSync->syncSlugForArticle(
                $article,
                $sideEffect,
                $slug ?? (string) ($article->slug ?? ''),
            ),
            default => $this->articleSync->syncForArticle($article, $sideEffect, $seoOverride),
        };

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $article = $article->fresh() ?? $article;
        $wpPostId = (int) ($result['wp_post_id'] ?? $article->wp_post_id ?? 0);

        return array_merge($result, [
            'article_id' => (int) $article->id,
            'post_type' => ArticlePostTypeResolver::resolve($article),
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'wordpress_connection_id' => (int) ($article->site_id ?? 0) ?: null,
            'sync_status' => 'completed',
        ]);
    }
}
