<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoArticle;

final class ArticlePostTypeResolver
{
    public static function resolve(SeoArticle $article): string
    {
        $type = trim((string) ($article->type ?? ''));
        if ($type !== '') {
            return $type;
        }

        $article->loadMissing('articleMetas');
        $wpPostType = trim((string) (
            $article->articleMetas->firstWhere('meta_key', 'wp_post_type')?->meta_value ?? ''
        ));

        return $wpPostType !== '' ? $wpPostType : 'article';
    }
}
