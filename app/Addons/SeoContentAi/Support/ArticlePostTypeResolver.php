<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;

final class ArticlePostTypeResolver
{
    public static function resolve(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        $wpEntity = strtolower(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_entity')?->meta_value ?? ''
        )));
        if ($wpEntity === 'term') {
            $taxonomy = app(\App\Addons\SeoContentAi\Services\WordPressArticleContentService::class)
                ->resolveWpTaxonomy($article);

            return match ($taxonomy) {
                'product_cat' => SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
                'category' => SeoProjectTask::POST_TYPE_CATEGORY,
                default => SeoProjectTask::normalizePostType((string) ($article->type ?? '')),
            };
        }

        // Laravel articles.type là nguồn sự thật cho editor / rewrite.
        // wp_post_type chỉ fallback khi type local trống (tránh meta WP stale ép nhầm product).
        $type = trim((string) ($article->type ?? ''));
        if ($type !== '') {
            return SeoProjectTask::normalizePostType($type);
        }

        $wpPostType = strtolower(trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_post_type')?->meta_value ?? ''
        )));

        if ($wpPostType !== '') {
            return self::fromWpPostType($wpPostType);
        }

        return SeoProjectTask::POST_TYPE_ARTICLE;
    }

    private static function fromWpPostType(string $wpPostType): string
    {
        return match ($wpPostType) {
            'product' => SeoProjectTask::POST_TYPE_PRODUCT,
            'product_cat', 'product_category' => SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY,
            'category' => SeoProjectTask::POST_TYPE_CATEGORY,
            'post', 'article' => SeoProjectTask::POST_TYPE_ARTICLE,
            default => SeoProjectTask::normalizePostType($wpPostType),
        };
    }
}
